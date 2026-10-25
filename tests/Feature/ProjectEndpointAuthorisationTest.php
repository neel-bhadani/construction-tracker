<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Building;
use App\Models\Project;
use App\Models\User;
use App\Services\ScheduleRecalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every project and building endpoint, hit directly as viewer, editor and admin. Viewers read;
 * editors and admins manage; an existing building's start dates are the admin's alone. Refused
 * requests must leave the database exactly as it was.
 */
class ProjectEndpointAuthorisationTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Project $empty;

    private Building $building;

    private Building $spare;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-10-15 12:00');
        $this->project = Project::create(['name' => 'Test project', 'planned_start_date' => '2026-04-05']);
        $this->empty = Project::create(['name' => 'Empty project', 'planned_start_date' => '2026-04-05']);
        $this->building = Building::create(['project_id' => $this->project->id, 'name' => 'Tower A', 'sort_order' => 1]);
        $this->spare = Building::create(['project_id' => $this->project->id, 'name' => 'Tower B', 'sort_order' => 2]);
        DB::table('activities')->insert(['building_id' => $this->building->id, 'description' => 'Root', 'offset_days' => 10, 'planned_date' => '2026-04-05', 'sort_order' => 1]);
        app(ScheduleRecalculator::class)->recalculate($this->building);
    }

    /** endpoint => [viewer, editor, admin] expected status */
    private const EXPECTED = [
        'list projects' => [200, 200, 200],
        'view a project' => [200, 200, 200],
        'view a building' => [200, 200, 200],
        'create a project' => [403, 302, 302],
        'edit a project' => [403, 302, 302],
        'delete an empty project' => [403, 302, 302],
        'add an empty building' => [403, 302, 302],
        'add a copied building' => [403, 302, 302],
        'upload a workbook' => [403, 200, 200],
        'edit a building' => [403, 302, 302],
        'reorder buildings' => [403, 302, 302],
        'delete a building' => [403, 302, 302],
        'change a building\'s start dates' => [403, 403, 302],
    ];

    /**
     * @return array<string, array{string, Role, int}>
     */
    public static function cases(): array
    {
        $cases = [];
        foreach (self::EXPECTED as $endpoint => $statuses) {
            foreach ([Role::Viewer, Role::Editor, Role::Admin] as $index => $role) {
                $cases["{$role->value}: {$endpoint}"] = [$endpoint, $role, $statuses[$index]];
            }
        }

        return $cases;
    }

    #[DataProvider('cases')]
    public function test_endpoint_as_role(string $endpoint, Role $role, int $expected): void
    {
        $user = User::factory()->role($role)->create();
        $before = $this->snapshot();
        $p = $this->project->id;
        $b = $this->building->id;

        $response = match ($endpoint) {
            'list projects' => $this->actingAs($user)->get('/projects'),
            'view a project' => $this->actingAs($user)->get("/projects/{$p}"),
            'view a building' => $this->actingAs($user)->get("/projects/{$p}/buildings/{$b}"),
            'create a project' => $this->actingAs($user)->post('/projects', ['name' => 'New', 'planned_start_date' => '2027-01-01', 'timezone' => 'Asia/Kolkata']),
            'edit a project' => $this->actingAs($user)->patch("/projects/{$p}", ['name' => 'Renamed', 'planned_start_date' => '2026-04-05', 'timezone' => 'Asia/Kolkata']),
            'delete an empty project' => $this->actingAs($user)->delete("/projects/{$this->empty->id}"),
            'add an empty building' => $this->actingAs($user)->post("/projects/{$p}/buildings", ['name' => 'Tower C', 'type' => 'building', 'plan' => 'empty']),
            'add a copied building' => $this->actingAs($user)->post("/projects/{$p}/buildings", ['name' => 'Tower C', 'type' => 'building', 'plan' => 'copy', 'copy_from' => $b]),
            'upload a workbook' => $this->actingAs($user)->post('/imports', ['file' => $this->workbook()], ['Accept' => 'application/json']),
            'edit a building' => $this->actingAs($user)->patch("/projects/{$p}/buildings/{$b}", ['name' => 'Tower A1', 'type' => 'building']),
            'reorder buildings' => $this->actingAs($user)->patch("/projects/{$p}/buildings/{$this->spare->id}/move", ['direction' => 'up']),
            'delete a building' => $this->actingAs($user)->delete("/projects/{$p}/buildings/{$this->spare->id}"),
            'change a building\'s start dates' => $this->actingAs($user)->patch("/projects/{$p}/buildings/{$b}/dates", ['planned_start_date' => '2026-05-01', 'actual_start_date' => null]),
        };

        $response->assertStatus($expected);
        $response->assertSessionHasNoErrors();

        if ($expected === 403) {
            $this->assertSame($before, $this->snapshot(), 'A refused request changed the database.');
        }
    }

    /** A refusal says so in words the client's staff understand, not a bare status code. */
    public function test_a_viewers_refusal_is_explained_in_plain_language(): void
    {
        $viewer = User::factory()->create();

        $this->actingAs($viewer)->postJson('/projects', ['name' => 'New', 'planned_start_date' => '2027-01-01'])
            ->assertForbidden()
            ->assertJson(['message' => 'Your role can view projects but not change them. Ask an editor or admin.']);
        $this->actingAs(User::factory()->editor()->create())
            ->patchJson("/projects/{$this->project->id}/buildings/{$this->building->id}/dates", ['planned_start_date' => '2026-05-01'])
            ->assertForbidden()
            ->assertJson(['message' => 'Only an admin can change a building\'s start dates: they move every planned date in it.']);
    }

    public function test_guest_is_sent_to_login_from_every_endpoint(): void
    {
        $p = $this->project->id;
        $b = $this->building->id;

        foreach ([
            ['get', '/projects'], ['get', "/projects/{$p}"], ['post', '/projects'], ['patch', "/projects/{$p}"], ['delete', "/projects/{$p}"],
            ['post', "/projects/{$p}/buildings"], ['get', "/projects/{$p}/buildings/{$b}"], ['patch', "/projects/{$p}/buildings/{$b}"],
            ['patch', "/projects/{$p}/buildings/{$b}/dates"], ['patch', "/projects/{$p}/buildings/{$b}/move"], ['delete', "/projects/{$p}/buildings/{$b}"],
            ['post', '/imports'],
        ] as [$method, $url]) {
            $this->{$method}($url)->assertRedirect('/login');
        }

        $this->assertSame(2, Project::count());
    }

    private function workbook(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'plan').'.xlsx';
        copy(base_path('docs/workbooks/SV_A3.xlsx'), $path);

        return new UploadedFile($path, 'SV_A3.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        $rows = fn (string $table) => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        return [
            'projects' => $rows('projects'),
            'buildings' => $rows('buildings'),
            'activities' => $rows('activities'),
            'project_revisions' => $rows('project_revisions'),
            'building_revisions' => $rows('building_revisions'),
            'activity_revisions' => $rows('activity_revisions'),
            'uploads' => count(glob(sys_get_temp_dir().'/timeline-test-imports-'.getmypid().'/*.xlsx') ?: []),
        ];
    }
}
