<?php

namespace Tests\Feature;

use App\Import\ImportUploads;
use App\Models\Building;
use App\Models\Project;
use App\Models\User;
use App\Services\ScheduleRecalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * One account cannot see or touch another's data: every page and API route that names a project,
 * building or activity answers 404 to anyone but its owner, and changes nothing.
 *
 * The route list is walked, not copied: a signed-in route without a case below fails
 * test_every_signed_in_route_has_a_case, so a new route cannot ship without an owner check.
 */
class OwnershipTest extends TestCase
{
    use RefreshDatabase;

    /** Routes about the signed-in account itself, never about someone else's records. */
    private const ACCOUNT_ROUTES = ['main', 'logout', 'profile.edit', 'profile.update', 'profile.password', 'help', 'projects.index', 'projects.store', 'imports.store'];

    /** Route name => what the request does, against the owner's records. */
    private const OWNED_ROUTES = [
        'projects.show' => 'view a project',
        'projects.update' => 'edit a project',
        'projects.destroy' => 'delete a project',
        'buildings.store' => 'add a building',
        'buildings.show' => 'view a building',
        'buildings.chart' => 'read a building\'s chart',
        'buildings.update' => 'edit a building',
        'buildings.dates' => 'change a building\'s start dates',
        'buildings.move' => 'reorder buildings',
        'buildings.destroy' => 'delete a building',
        'scopes.legacy' => 'follow an old building link',
        'activities.actual' => 'record an actual date',
        'activities.store' => 'add an activity',
        'activities.update' => 'edit an activity',
        'activities.destroy' => 'delete an activity',
        'activities.preview-new' => 'preview a new activity',
        'activities.preview-edit' => 'preview an edit',
    ];

    private User $stranger;

    private Project $project;

    private Project $empty;

    private Building $building;

    private Building $spare;

    private int $root;

    private int $leaf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-10-15 12:00');

        $this->project = Project::create(['owner_id' => $this->owner()->id, 'name' => 'Test project', 'planned_start_date' => '2026-04-05']);
        $this->empty = Project::create(['owner_id' => $this->owner()->id, 'name' => 'Empty project', 'planned_start_date' => '2026-04-05']);
        $this->building = Building::create(['project_id' => $this->project->id, 'name' => 'Tower A', 'sort_order' => 1]);
        $this->spare = Building::create(['project_id' => $this->project->id, 'name' => 'Tower B', 'sort_order' => 2]);
        $this->root = DB::table('activities')->insertGetId(['building_id' => $this->building->id, 'description' => 'Root', 'offset_days' => 10, 'planned_date' => '2026-04-05', 'sort_order' => 1]);
        $this->leaf = DB::table('activities')->insertGetId(['building_id' => $this->building->id, 'description' => 'Leaf', 'predecessor_id' => $this->root, 'offset_days' => 5, 'planned_date' => '2026-04-05', 'sort_order' => 2]);
        app(ScheduleRecalculator::class)->recalculate($this->building);

        // the other account has data of its own, with the same names
        $this->stranger = User::factory()->create(['name' => 'Someone Else']);
        $theirs = Project::create(['owner_id' => $this->stranger->id, 'name' => 'Test project', 'planned_start_date' => '2026-04-05']);
        Building::create(['project_id' => $theirs->id, 'name' => 'Tower A', 'sort_order' => 1]);
    }

    public function test_every_signed_in_route_has_a_case(): void
    {
        $signedIn = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn (Route $route) => in_array('auth', $route->gatherMiddleware(), true))
            ->map(fn (Route $route) => $route->getName() ?? implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $missing = $signedIn->diff([...self::ACCOUNT_ROUTES, ...array_keys(self::OWNED_ROUTES)])->values()->all();
        $stale = collect([...self::ACCOUNT_ROUTES, ...array_keys(self::OWNED_ROUTES)])->diff($signedIn)->values()->all();

        $this->assertSame([], $missing, 'These signed-in routes have no ownership case in OwnershipTest: '.implode(', ', $missing));
        $this->assertSame([], $stale, 'OwnershipTest lists routes that no longer exist: '.implode(', ', $stale));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function ownedRoutes(): array
    {
        return collect(self::OWNED_ROUTES)->mapWithKeys(fn (string $label, string $name) => [$label => [$name]])->all();
    }

    #[DataProvider('ownedRoutes')]
    public function test_another_account_gets_a_404_and_changes_nothing(string $route): void
    {
        $before = $this->snapshot();

        $this->request($route, $this->stranger)->assertNotFound();

        $this->assertSame($before, $this->snapshot(), 'A refused request changed the database.');
    }

    /** The JSON endpoints say "not found" too; nothing about who owns what. */
    #[DataProvider('ownedRoutes')]
    public function test_another_account_gets_a_404_as_json(string $route): void
    {
        $this->request($route, $this->stranger, json: true)->assertNotFound()->assertJsonMissingPath('errors');
    }

    #[DataProvider('ownedRoutes')]
    public function test_the_owner_can(string $route): void
    {
        $response = $this->request($route, $this->owner());

        $this->assertTrue($response->isSuccessful() || $response->isRedirect(), "{$route} answered {$response->status()}");
        $response->assertSessionHasNoErrors();
    }

    #[DataProvider('ownedRoutes')]
    public function test_a_guest_is_sent_to_login(string $route): void
    {
        $this->request($route, null)->assertRedirect('/login');
    }

    public function test_the_lists_show_only_the_accounts_own_projects(): void
    {
        $this->actingAs($this->stranger)->get('/projects')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('projects', 1)
                ->where('projects.0.name', 'Test project')
                ->whereNot('projects.0.id', $this->project->id));

        // the header's project switcher, on a page that does not replace it with its own list
        $this->actingAs($this->stranger)->get('/profile')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('projects', 1)->whereNot('projects.0.id', $this->project->id));

        $this->actingAs($this->owner())->get('/projects')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('projects', 2));
    }

    public function test_a_plan_can_be_copied_only_from_the_accounts_own_buildings(): void
    {
        $theirs = $this->stranger->projects()->sole();

        $this->actingAs($this->stranger)->get("/projects/{$theirs->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('copySources', 1)->where('copySources.0.label', 'Test project › Tower A')
                ->whereNot('copySources.0.id', $this->building->id));

        $this->actingAs($this->stranger)
            ->post("/projects/{$theirs->id}/buildings", ['name' => 'Copied', 'type' => 'building', 'plan' => 'copy', 'copy_from' => $this->building->id])
            ->assertSessionHasErrors(['copy_from' => 'That building no longer exists. Choose another.']);
        $this->assertSame(0, Building::where('name', 'Copied')->count());
    }

    public function test_an_uploaded_workbook_can_be_imported_only_by_the_session_that_uploaded_it(): void
    {
        $token = app(ImportUploads::class)->store(UploadedFile::fake()->create('plan.xlsx', 1));
        $theirs = $this->stranger->projects()->sole();

        $this->actingAs($this->stranger)
            ->post("/projects/{$theirs->id}/buildings", ['name' => 'Imported', 'type' => 'building', 'plan' => 'import', 'import_token' => $token, 'sheet' => 'Tower A'])
            ->assertSessionHasErrors(['import_token' => 'The uploaded file has expired. Choose it again.']);
        $this->assertSame(0, Building::where('name', 'Imported')->count());
    }

    private function request(string $route, ?User $user, bool $json = false): TestResponse
    {
        $p = $this->project->id;
        $b = $this->building->id;
        [$method, $url, $body] = match ($route) {
            'projects.show' => ['get', "/projects/{$p}", []],
            'projects.update' => ['patch', "/projects/{$p}", ['name' => 'Renamed', 'planned_start_date' => '2026-04-05', 'timezone' => 'Asia/Kolkata']],
            'projects.destroy' => ['delete', "/projects/{$this->empty->id}", []],
            'buildings.store' => ['post', "/projects/{$p}/buildings", ['name' => 'Tower C', 'type' => 'building', 'plan' => 'empty']],
            'buildings.show' => ['get', "/projects/{$p}/buildings/{$b}", []],
            'buildings.chart' => ['get', "/projects/{$p}/buildings/{$b}/chart", []],
            'buildings.update' => ['patch', "/projects/{$p}/buildings/{$b}", ['name' => 'Tower A1', 'type' => 'building']],
            'buildings.dates' => ['patch', "/projects/{$p}/buildings/{$b}/dates", ['planned_start_date' => '2026-05-01', 'actual_start_date' => null]],
            'buildings.move' => ['patch', "/projects/{$p}/buildings/{$this->spare->id}/move", ['direction' => 'up']],
            'buildings.destroy' => ['delete', "/projects/{$p}/buildings/{$this->spare->id}", []],
            'scopes.legacy' => ['get', "/scopes/{$b}", []],
            'activities.actual' => ['patch', "/activities/{$this->root}/actual", ['actual_date' => '2026-04-20', 'notes' => 'Poured']],
            'activities.store' => ['post', "/buildings/{$b}/activities", $this->plan()],
            'activities.update' => ['patch', "/activities/{$this->leaf}", $this->plan()],
            'activities.destroy' => ['delete', "/activities/{$this->leaf}", []],
            'activities.preview-new' => ['post', "/buildings/{$b}/activities/preview", $this->chain()],
            'activities.preview-edit' => ['post', "/activities/{$this->leaf}/preview", $this->chain()],
        };

        if ($user !== null) {
            $this->actingAs($user);
        }

        return $json ? $this->json($method, $url, $body) : $this->{$method}($url, $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function plan(): array
    {
        return ['description' => 'New activity', 'is_break' => false, 'notes' => null, 'adjustment_reason' => null, ...$this->chain()];
    }

    /**
     * @return array<string, mixed>
     */
    private function chain(): array
    {
        return ['predecessor_id' => $this->root, 'offset_days' => 20, 'date_adjustment_days' => 0];
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
        ];
    }
}
