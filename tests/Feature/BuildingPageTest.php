<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Building;
use App\Models\Project;
use App\Services\BuildingMetricsService;
use App\Services\LeadProjectionService;
use App\Services\ProjectEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The building page: the timeline screen, unchanged, inside its project.
 */
class BuildingPageTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seedClientWorkbooks();
        $this->travelTo('2026-10-15 12:00');
        $this->project = Project::sole();
    }

    public function test_home_is_the_projects_page(): void
    {
        $this->actingAs($this->owner())->get('/')->assertRedirect('/projects');
    }

    public function test_each_building_has_its_own_url_inside_its_project(): void
    {
        $towerB = Building::where('name', 'Tower B')->sole();

        $this->actingAs($this->owner())->get("/projects/{$this->project->id}/buildings/{$towerB->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Buildings/Show')
                ->where('project.name', 'Shaligram Vrundavan')
                ->where('building.name', 'Tower B')
                ->has('buildings', 3)
                ->where('buildings.0.name', 'Tower A'));
    }

    public function test_links_from_before_projects_existed_redirect_to_the_new_address(): void
    {
        $towerA = Building::where('name', 'Tower A')->sole();

        $this->actingAs($this->owner())->get("/scopes/{$towerA->id}")
            ->assertStatus(301)
            ->assertRedirect("/projects/{$this->project->id}/buildings/{$towerA->id}");
    }

    public function test_a_building_under_the_wrong_project_is_not_found(): void
    {
        $other = Project::create(['owner_id' => $this->owner()->id, 'name' => 'Other', 'planned_start_date' => '2026-01-01']);
        $towerA = Building::where('name', 'Tower A')->sole();

        $this->actingAs($this->owner())->get("/projects/{$other->id}/buildings/{$towerA->id}")->assertNotFound();
    }

    /** A deleted building's timeline leaves the app: neither its page nor its activities answer. */
    public function test_a_deleted_buildings_timeline_and_activities_are_not_found(): void
    {
        $towerA = Building::where('name', 'Tower A')->sole();
        $activity = Activity::where('building_id', $towerA->id)->whereNull('actual_date')->first();
        $owner = $this->owner();
        app(ProjectEditor::class)->deleteBuilding($towerA, $owner);

        $this->actingAs($owner)->get("/projects/{$this->project->id}/buildings/{$towerA->id}")->assertNotFound();
        $this->actingAs($owner)->get("/scopes/{$towerA->id}")->assertNotFound();
        $this->actingAs($owner)->patch("/activities/{$activity->id}/actual", ['actual_date' => '2026-10-01'])->assertNotFound();
        $this->actingAs($owner)->postJson("/buildings/{$towerA->id}/activities/preview", [])->assertNotFound();
        $this->assertNull($activity->fresh()->actual_date);
        $this->assertSame(52, Activity::where('building_id', $towerA->id)->count());
    }

    /** Every figure on the page is the services' output, passed through untouched. */
    public function test_figures_are_the_services_output_unchanged(): void
    {
        $towerB = Building::where('name', 'Tower B')->sole();
        $metrics = app(BuildingMetricsService::class)->forBuilding($towerB);
        $projection = app(LeadProjectionService::class)->project($metrics);

        $props = $this->actingAs($this->owner())->get("/projects/{$this->project->id}/buildings/{$towerB->id}")->viewData('page')['props'];

        $json = fn (mixed $value) => json_decode(json_encode($value), true);

        $this->assertSame($json($metrics), $json($props['metrics']));
        $this->assertSame($json($projection), $json($props['projection']));
        $this->assertSame(7, $json($props['metrics'])['currentLead']);
        $this->assertSame('3rd SLAB 15', collect($json($props['projection'])['activities'])->firstWhere('status', 'NEXT')['description']);
    }

    /** No roles: the page names the account and offers no permission flags. */
    public function test_the_page_carries_no_roles_or_permission_flags(): void
    {
        $towerA = Building::where('name', 'Tower A')->sole();

        $this->actingAs($this->owner())->get("/projects/{$this->project->id}/buildings/{$towerA->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.name', 'The Owner')
                ->missing('auth.user.role')
                ->missing('auth.user.canApproveUsers')
                ->missing('can'));
    }
}
