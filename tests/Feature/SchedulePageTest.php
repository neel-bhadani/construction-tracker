<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Project;
use App\Models\Scope;
use App\Models\User;
use App\Services\LeadProjectionService;
use App\Services\ScopeMetricsService;
use Database\Seeders\WorkbookSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SchedulePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(WorkbookSeeder::class);
        $this->travelTo('2026-10-15 12:00');
    }

    public function test_main_page_opens_on_the_first_scope_and_each_scope_has_its_own_url(): void
    {
        $user = User::factory()->create();
        $towerA = Scope::where('name', 'Tower A')->sole();
        $towerB = Scope::where('name', 'Tower B')->sole();

        $this->actingAs($user)->get('/')->assertRedirect("/scopes/{$towerA->id}");
        $this->actingAs($user)->get("/scopes/{$towerB->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Schedule')
                ->where('scope.name', 'Tower B')
                ->has('scopes', 3)
                ->where('scopes.0.name', 'Tower A'));
    }

    public function test_with_no_scopes_the_main_page_is_an_empty_state_not_a_404(): void
    {
        Project::query()->each(function ($project) {
            DB::table('activities')->whereIn('scope_id', $project->scopes()->pluck('id'))->update(['predecessor_id' => null]);
            $project->delete();
        });

        $this->actingAs(User::factory()->admin()->create())->get('/')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Empty')->where('can.approveUsers', true));
        $this->actingAs(User::factory()->create())->get('/')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Empty')->where('can.approveUsers', false));
    }

    /**
     * Every figure on the page is the services' output, passed through untouched.
     */
    public function test_figures_are_the_services_output_unchanged(): void
    {
        $scope = Scope::where('name', 'Tower B')->sole();
        $metrics = app(ScopeMetricsService::class)->forScope($scope);
        $projection = app(LeadProjectionService::class)->project($metrics);

        $props = $this->actingAs(User::factory()->create())->get("/scopes/{$scope->id}")->viewData('page')['props'];

        $json = fn (mixed $value) => json_decode(json_encode($value), true);

        $this->assertSame($json($metrics), $json($props['metrics']));
        $this->assertSame($json($projection), $json($props['projection']));
        $this->assertSame(7, $json($props['metrics'])['currentLead']);
        $this->assertSame('3rd SLAB 15', collect($json($props['projection'])['activities'])->firstWhere('status', 'NEXT')['description']);
    }

    public function test_the_page_shows_the_user_their_name_and_only_the_actions_their_role_has(): void
    {
        $scope = Scope::where('name', 'Tower A')->sole();
        $expected = [
            'viewer' => [false, false, false, false],
            'editor' => [true, false, false, false],
            'admin' => [true, true, true, true],
        ];

        foreach ($expected as $role => [$record, $manage, $dates, $approve]) {
            $user = User::factory()->role(Role::from($role))->create(['name' => "The {$role}"]);

            $this->actingAs($user)->get("/scopes/{$scope->id}")->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('auth.user.name', "The {$role}")
                    ->where('auth.user.role', $role)
                    ->where('can', [
                        'recordActual' => $record,
                        'manageActivities' => $manage,
                        'updateProjectDates' => $dates,
                        'approveUsers' => $approve,
                    ]));
        }
    }
}
