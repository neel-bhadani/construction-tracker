<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Scope;
use App\Models\User;
use App\Services\ScheduleRecalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every endpoint, hit directly as viewer, editor and admin. A hidden button protects nothing;
 * these do. Refused requests must also leave the database exactly as it was.
 */
class ScheduleEndpointAuthorisationTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Scope $scope;

    private int $root;

    private int $leaf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-10-15 12:00');
        $this->project = Project::create(['name' => 'Test project', 'planned_start_date' => '2026-04-05']);
        $this->scope = Scope::create(['project_id' => $this->project->id, 'name' => 'Tower A']);
        $this->root = DB::table('activities')->insertGetId(['scope_id' => $this->scope->id, 'description' => 'Root', 'offset_days' => 10, 'planned_date' => '2026-04-05', 'sort_order' => 1]);
        $this->leaf = DB::table('activities')->insertGetId(['scope_id' => $this->scope->id, 'description' => 'Leaf', 'predecessor_id' => $this->root, 'offset_days' => 5, 'planned_date' => '2026-04-05', 'sort_order' => 2]);
        app(ScheduleRecalculator::class)->recalculate($this->scope);
    }

    /** endpoint => [viewer, editor, admin] expected status */
    private const EXPECTED = [
        'view the page' => [200, 200, 200],
        'record an actual date' => [403, 302, 302],
        'add an activity' => [403, 403, 302],
        'edit an activity' => [403, 403, 302],
        'delete an activity' => [403, 403, 302],
        'preview a new activity' => [403, 403, 200],
        'preview an edit' => [403, 403, 200],
        'change the project dates' => [403, 403, 302],
        'list pending users' => [403, 403, 200],
        'approve a user' => [403, 403, 302],
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
        $pending = User::factory()->unapproved()->create();
        $before = $this->snapshot();

        $response = match ($endpoint) {
            'view the page' => $this->actingAs($user)->get("/scopes/{$this->scope->id}"),
            'record an actual date' => $this->actingAs($user)->patch("/activities/{$this->root}/actual", ['actual_date' => '2026-04-20', 'notes' => 'Poured']),
            'add an activity' => $this->actingAs($user)->post("/scopes/{$this->scope->id}/activities", $this->plan()),
            'edit an activity' => $this->actingAs($user)->patch("/activities/{$this->leaf}", $this->plan(['predecessor_id' => $this->root])),
            'delete an activity' => $this->actingAs($user)->delete("/activities/{$this->leaf}"),
            'preview a new activity' => $this->actingAs($user)->postJson("/scopes/{$this->scope->id}/activities/preview", $this->chain()),
            'preview an edit' => $this->actingAs($user)->postJson("/activities/{$this->leaf}/preview", $this->chain()),
            'change the project dates' => $this->actingAs($user)->patch("/projects/{$this->project->id}/dates", ['planned_start_date' => '2026-05-01', 'actual_start_date' => null]),
            'list pending users' => $this->actingAs($user)->getJson('/users/pending'),
            'approve a user' => $this->actingAs($user)->patch("/users/{$pending->id}/approve"),
        };

        $response->assertStatus($expected);
        $response->assertSessionHasNoErrors();

        if ($expected === 403) {
            $this->assertSame($before, $this->snapshot(), 'A refused request changed the database.');
            $this->assertNull($pending->fresh()->approved_at);
        }
    }

    /**
     * Authorisation runs before validation, so a viewer cannot probe what a schedule change
     * would do — not even through the validation errors.
     */
    public function test_viewer_cannot_probe_the_preview_even_with_an_invalid_or_cyclic_request(): void
    {
        $viewer = User::factory()->create();
        DB::table('activities')->where('id', $this->root)->update(['predecessor_id' => null]);

        foreach ([['offset_days' => -5], ['predecessor_id' => $this->leaf] + $this->chain(), $this->chain()] as $body) {
            $this->actingAs($viewer)->postJson("/activities/{$this->root}/preview", $body)
                ->assertForbidden()
                ->assertJsonMissingPath('errors')
                ->assertJsonMissingPath('changedCount');
        }
    }

    public function test_editor_cannot_probe_the_preview_either(): void
    {
        $this->actingAs(User::factory()->editor()->create())
            ->postJson("/activities/{$this->leaf}/preview", $this->chain())
            ->assertForbidden()
            ->assertJsonMissingPath('changedCount');
    }

    public function test_guest_is_sent_to_login_from_every_endpoint(): void
    {
        $this->get("/scopes/{$this->scope->id}")->assertRedirect('/login');
        $this->patch("/activities/{$this->root}/actual", [])->assertRedirect('/login');
        $this->post("/scopes/{$this->scope->id}/activities", [])->assertRedirect('/login');
        $this->post("/activities/{$this->leaf}/preview", [])->assertRedirect('/login');
        $this->delete("/activities/{$this->leaf}")->assertRedirect('/login');
        $this->patch("/projects/{$this->project->id}/dates", [])->assertRedirect('/login');
        $this->assertSame(2, Activity::count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function plan(array $overrides = []): array
    {
        return [
            'description' => 'New activity',
            'predecessor_id' => $this->root,
            'offset_days' => 7,
            'date_adjustment_days' => 0,
            'adjustment_reason' => null,
            'is_break' => false,
            'notes' => null,
            ...$overrides,
        ];
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
        return [
            'activities' => DB::table('activities')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
            'activity_revisions' => DB::table('activity_revisions')->count(),
            'projects' => DB::table('projects')->get()->map(fn ($row) => (array) $row)->all(),
            'project_revisions' => DB::table('project_revisions')->count(),
        ];
    }
}
