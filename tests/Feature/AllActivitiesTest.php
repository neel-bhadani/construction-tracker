<?php

namespace Tests\Feature;

use App\Models\Activity;
use App\Models\Building;
use App\Models\Project;
use App\Services\PlanWriter;
use App\Services\ProjectEditor;
use App\Services\SubActivityEditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The project page's All activities tab: every activity, grouped by timeline, Common activities
 * first. Its queries do not grow with the rows: activities and sub-activities are read once each.
 */
class AllActivitiesTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-10-15 12:00');
        $editor = app(ProjectEditor::class);
        $this->project = $editor->createProject(['name' => 'All Test', 'details' => null, 'planned_start_date' => '2026-04-01'], $this->owner());
        $editor->createBuilding($this->project, ['name' => 'Tower B'], $this->owner());
        $editor->createBuilding($this->project, ['name' => 'Tower A'], $this->owner());
    }

    public function test_the_groups_are_common_activities_then_the_buildings_in_order(): void
    {
        $this->plan('Common activities', 2);
        $this->plan('Tower B', 3, actuals: 1);
        $this->plan('Tower A', 1);
        $first = Activity::where('description', 'Tower B 1')->sole();
        app(SubActivityEditor::class)->create(Activity::where('description', 'Tower B 2')->sole(), ['name' => 'Rebar', 'progress' => 50], $this->owner());

        $this->page()->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'all')
            ->where('today', '2026-10-15')
            ->has('groups', 3)
            ->where('groups.0.name', 'Common activities')
            ->where('groups.0.type', 'common_work')
            ->where('groups.1.name', 'Tower B')
            ->where('groups.2.name', 'Tower A')
            ->has('groups.1.rows', 3)
            ->where('groups.1.rows.0.work_id', $first->work_id)
            ->where('groups.1.rows.0.status', 'Done')
            ->where('groups.1.rows.0.actualDate', '2026-04-11')
            ->where('groups.1.rows.0.progress', 100)
            ->where('groups.1.rows.1.status', 'NEXT')
            ->where('groups.1.rows.1.progress', 50)
            ->where('groups.1.rows.1.sub_activities.0.name', 'Rebar')
            ->where('groups.1.rows.2.status', 'Pending')
            ->where('groups.1.rows.2.actualOrForecast', '2026-05-01')
            ->where('groups.1.currentLead', 0)
            ->where('groups.1.earliest', '2026-04-01')
            ->where('groups.1.progress', 10 / 30)
            ->where('project.code', 'AT'));
    }

    /** The figures are the building page's own: the same services, the same numbers. */
    public function test_a_groups_figures_match_its_timeline(): void
    {
        $this->plan('Tower B', 4, actuals: 2);
        $tower = Building::where('name', 'Tower B')->sole();

        $timeline = $this->actingAs($this->owner())->get("/projects/{$this->project->id}/buildings/{$tower->id}")->viewData('page')['props'];
        $group = collect($this->page()->viewData('page')['props']['groups'])->firstWhere('name', 'Tower B');

        $metrics = (array) json_decode(json_encode($timeline['metrics']), true);
        $this->assertSame([$metrics['progress'], $metrics['currentLead'], $metrics['forecastHoldLead']], [$group['progress'], $group['currentLead'], $group['forecastFinish']]);
        $projected = collect(json_decode(json_encode($timeline['projection']), true)['activities']);
        $this->assertSame($projected->pluck('actualOrForecast')->all(), array_column($group['rows'], 'actualOrForecast'));
    }

    public function test_the_queries_do_not_grow_with_the_rows(): void
    {
        $this->plan('Common activities', 2);
        $this->plan('Tower B', 2);
        $this->plan('Tower A', 2);
        $few = $this->queries();

        // ten times the activities, and sub-activities on each
        foreach (['Common activities', 'Tower B', 'Tower A'] as $timeline) {
            $this->plan($timeline, 20, prefix: 'More ');
        }
        foreach (Activity::where('is_break', false)->get() as $activity) {
            foreach (range(1, 3) as $n) {
                app(SubActivityEditor::class)->create($activity, ['name' => "Step {$n}", 'progress' => 25], $this->owner());
            }
        }
        $many = $this->queries();

        $this->assertSame($few, $many, 'The All activities page reads more often with more rows.');
    }

    /**
     * $count activities in a chain on that timeline, ten days apart; the first $actuals done on
     * their planned day.
     */
    private function plan(string $timeline, int $count, int $actuals = 0, string $prefix = ''): void
    {
        $building = Building::where('project_id', $this->project->id)->where('name', $timeline)->sole();
        $rows = [];
        foreach (range(1, $count) as $n) {
            $rows[$n] = [
                'description' => "{$prefix}{$timeline} {$n}", 'predecessor' => $n === 1 ? null : $n - 1, 'offset_days' => 10,
                'date_adjustment_days' => 0, 'adjustment_reason' => null, 'is_break' => false,
                'sort_order' => (int) Activity::where('building_id', $building->id)->max('sort_order') + $n,
                'actual_date' => $n <= $actuals ? date('Y-m-d', strtotime('2026-04-01 +'.($n * 10).' days')) : null,
            ];
        }
        if ($prefix !== '') {
            // a separate chain: its own root
            $rows[1]['predecessor'] = null;
        }
        DB::transaction(fn () => app(PlanWriter::class)->write($building, $rows, $this->owner()));
    }

    private function page()
    {
        return $this->actingAs($this->owner())->get("/projects/{$this->project->id}/activities")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Projects/Show'));
    }

    private function queries(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->page();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
