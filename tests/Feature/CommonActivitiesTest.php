<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Building;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Each project's Common activities: created with the project, shown on the project page as its own
 * tab (not a building tab or card), worked on exactly like a building's timeline.
 */
class CommonActivitiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-10-15 12:00');
    }

    public function test_a_new_project_gets_its_common_activities_and_a_code(): void
    {
        $project = $this->newProject('Shaligram Vrundavan');

        $common = $project->commonActivities()->sole();
        $this->assertSame(['Common activities', 'common_work', '2026-04-05', 0],
            [$common->name, $common->type->value, $common->planned_start_date->toDateString(), $common->sort_order]);
        $this->assertSame('SV', $project->code);
        $this->assertSame(['created', 'Common activities'], [$common->revisions()->sole()->field, $common->revisions()->sole()->new_value]);
    }

    public function test_the_code_can_be_given_is_upper_cased_and_unique_among_the_owners_projects(): void
    {
        $this->assertSame('ABC1', $this->newProject('First', ' abc1 ')->code);

        $this->actingAs($this->owner())->post('/projects', $this->projectData('Second', 'ABC1'))
            ->assertSessionHasErrors(['code' => 'Another of your projects uses this code.']);
        $this->actingAs($this->owner())->post('/projects', $this->projectData('Second', 'A-1'))
            ->assertSessionHasErrors(['code' => 'The code is letters A–Z and digits only.']);

        // the same initials as one already taken get a number
        $this->newProject('Sunny Valley');
        $this->assertSame('SV2', $this->newProject('Silver Vista')->code);
    }

    public function test_the_project_page_shows_buildings_and_common_activities_apart(): void
    {
        $project = $this->newProject('Shaligram Vrundavan');
        $this->actingAs($this->owner())->post("/projects/{$project->id}/buildings", ['name' => 'Tower A', 'plan' => 'empty']);

        $this->actingAs($this->owner())->get("/projects/{$project->id}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Projects/Show')
                ->where('tab', 'buildings')
                ->where('project.code', 'SV')
                ->where('project.buildingsCount', 1)
                ->has('project.buildings', 1)
                ->where('project.buildings.0.name', 'Tower A')
                ->where('project.common.name', 'Common activities')
                ->missing('timeline'));

        $common = $project->commonActivities()->sole();
        $this->actingAs($this->owner())->get("/projects/{$project->id}/common")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Projects/Show')
                ->where('tab', 'common')
                ->where('timeline.building.id', $common->id)
                ->where('timeline.building.type', 'common_work')
                ->has('timeline.metrics')
                ->has('timeline.projection')
                ->has('timeline.chart')
                ->has('timeline.activities', 0));
    }

    public function test_activities_are_added_there_exactly_as_in_a_building(): void
    {
        $project = $this->newProject('Shaligram Vrundavan');
        $common = $project->commonActivities()->sole();

        $this->actingAs($this->owner())->post("/buildings/{$common->id}/activities", [
            'description' => 'Site fencing', 'predecessor_id' => null, 'offset_days' => 10, 'date_adjustment_days' => 0,
            'adjustment_reason' => null, 'is_break' => false, 'actual_date' => null, 'notes' => null,
        ])->assertSessionHas('success', 'Added [SV-001 Site fencing].');

        $fencing = Activity::sole();
        $this->assertSame([$common->id, '2026-04-15'], [$fencing->building_id, $fencing->planned_date->toDateString()]);

        $this->actingAs($this->owner())->get("/projects/{$project->id}/common")
            ->assertInertia(fn (Assert $page) => $page->where('timeline.activities.0.work_id', 'SV-001')
                ->where('timeline.metrics.totalPlannedDays', 10));
    }

    public function test_a_project_has_one_live_common_timeline(): void
    {
        $project = $this->newProject('Shaligram Vrundavan');

        $this->expectException(QueryException::class);
        Building::create(['project_id' => $project->id, 'name' => 'Site works', 'type' => 'common_work']);
    }

    public function test_an_empty_project_is_deleted_with_its_empty_common_activities(): void
    {
        $project = $this->newProject('Cancelled');
        $common = $project->commonActivities()->sole();

        $this->actingAs($this->owner())->delete("/projects/{$project->id}")->assertRedirect('/projects');

        $this->assertSoftDeleted($project);
        $this->assertSoftDeleted($common);
    }

    public function test_a_project_whose_common_activities_have_work_is_not_deleted(): void
    {
        $project = $this->newProject('Busy');
        $this->actingAs($this->owner())->post("/buildings/{$project->commonActivities()->sole()->id}/activities", [
            'description' => 'Site fencing', 'predecessor_id' => null, 'offset_days' => 10, 'date_adjustment_days' => 0,
            'adjustment_reason' => null, 'is_break' => false, 'actual_date' => null, 'notes' => null,
        ]);

        $this->actingAs($this->owner())->delete("/projects/{$project->id}")
            ->assertSessionHasErrors(['project' => '[Busy] still has the 1 common activity. Delete them first.']);
        $this->assertNotSoftDeleted($project);
    }

    private function newProject(string $name, ?string $code = null): Project
    {
        $this->actingAs($this->owner())->post('/projects', $this->projectData($name, $code))->assertSessionHasNoErrors();

        return Project::where('name', $name)->sole();
    }

    /**
     * @return array<string, mixed>
     */
    private function projectData(string $name, ?string $code): array
    {
        return ['name' => $name, 'code' => $code, 'details' => null, 'planned_start_date' => '2026-04-05', 'timezone' => 'Asia/Kolkata'];
    }
}
