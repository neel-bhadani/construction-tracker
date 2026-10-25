<?php

namespace Tests\Feature;

use App\Data\BuildingProgress;
use App\Data\FloorProgress;
use App\Enums\FloorStatus;
use App\Models\Activity;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Project;
use App\Services\FloorEditor;
use App\Services\ProgressService;
use App\Services\ProjectSummaryService;
use App\Services\ScheduleRecalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Progress at all three levels on a hand-made building with three floors and common work. Every
 * figure below is worked out by hand from this table; none is read back from the code.
 *
 * Tower 1 starts (planned and actual) 1 Jan 2026; today is 1 Mar 2026.
 *
 *   row  activity    floor   after  offset  work-day  planned  actual  work-days
 *   1    Excavation  Whole   start  10      10        11 Jan   10 Jan  10
 *   2    GF slab     Ground  1      20      30        31 Jan   29 Jan  20
 *   3    GF walls    Ground  2      10      40        10 Feb   12 Feb  10
 *   4    1F slab     1st     2      15      45        15 Feb           15
 *   5    Diwali      break   4      10      55        25 Feb            0
 *   6    1F walls    1st     5      10      65         7 Mar           10   (65 − 55)
 *   7    2F slab     2nd     5       5      60         2 Mar            5   (60 − 55)
 *   8    Handover    Whole   6       5      70        12 Mar            5   (70 − 65)
 *
 * Building (the workbook's C8, C11, C12, C13): total 70; furthest done work-day 40 (row 3), so
 * 40/70. Latest actual 12 Feb is row 3, planned 10 Feb: lead −2, so every pending row's Lead Tracker
 * forecast is its planned date + 2.
 *
 *   floor           work-days  done  %      done/all  planned  finish                status
 *   Ground floor    30         30    100%   2 of 2    10 Feb   actual 12 Feb         Done
 *   1st floor       25          0      0%   0 of 2     7 Mar   forecast 9 Mar        Behind (1F slab, 15 Feb, is past)
 *   2nd floor        5          0      0%   0 of 1     2 Mar   forecast 4 Mar        Not started (2 Mar is not past yet)
 *   Whole building  15         10    2/3    1 of 2    12 Mar   forecast 14 Mar       In progress (Diwali left out)
 *
 * The floors add up to 75 work-days, the building to 70: the building measures how far along its
 * longest chain the work has reached, the floors add each activity's own days.
 *
 * Tower 2, one activity of 30 days, done: 30 of 30. Project: (40 + 30) / (70 + 30) = 0.7.
 */
class FloorProgressTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Building $building;

    /** @var array<string, Floor> */
    private array $floors = [];

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-03-01 12:00');
        $this->project = Project::create(['owner_id' => $this->owner()->id, 'name' => 'Hand-made', 'planned_start_date' => '2026-01-01']);
        $this->building = Building::create(['project_id' => $this->project->id, 'name' => 'Tower 1', 'planned_start_date' => '2026-01-01', 'actual_start_date' => '2026-01-01', 'sort_order' => 1]);

        foreach ([['Ground floor', 0], ['1st floor', 1], ['2nd floor', 2]] as [$name, $level]) {
            $this->floors[$name] = Floor::create(['building_id' => $this->building->id, 'name' => $name, 'level' => $level]);
        }

        foreach ([
            ['Excavation', null, null, 10, false, '2026-01-10'],
            ['GF slab', 'Ground floor', 'Excavation', 20, false, '2026-01-29'],
            ['GF walls', 'Ground floor', 'GF slab', 10, false, '2026-02-12'],
            ['1F slab', '1st floor', 'GF slab', 15, false, null],
            ['Diwali', null, '1F slab', 10, true, null],
            ['1F walls', '1st floor', 'Diwali', 10, false, null],
            ['2F slab', '2nd floor', 'Diwali', 5, false, null],
            ['Handover', null, '1F walls', 5, false, null],
        ] as $row => [$name, $floor, $after, $offset, $break, $actual]) {
            $this->ids[$name] = DB::table('activities')->insertGetId([
                'building_id' => $this->building->id,
                'floor_id' => $floor === null ? null : $this->floors[$floor]->id,
                'description' => $name,
                'predecessor_id' => $after === null ? null : $this->ids[$after],
                'offset_days' => $offset, 'is_break' => $break, 'actual_date' => $actual,
                'planned_date' => '2026-01-01', 'sort_order' => $row + 1,
            ]);
        }
        app(ScheduleRecalculator::class)->recalculate($this->building);

        $tower2 = Building::create(['project_id' => $this->project->id, 'name' => 'Tower 2', 'planned_start_date' => '2026-01-01', 'actual_start_date' => '2026-01-01', 'sort_order' => 2]);
        DB::table('activities')->insert(['building_id' => $tower2->id, 'description' => 'Everything', 'offset_days' => 30, 'actual_date' => '2026-01-31', 'planned_date' => '2026-01-01', 'sort_order' => 1]);
        app(ScheduleRecalculator::class)->recalculate($tower2);
    }

    public function test_the_hand_made_plan_is_as_described(): void
    {
        $this->assertSame([
            'Excavation' => [10, '2026-01-11'], 'GF slab' => [30, '2026-01-31'], 'GF walls' => [40, '2026-02-10'],
            '1F slab' => [45, '2026-02-15'], 'Diwali' => [55, '2026-02-25'], '1F walls' => [65, '2026-03-07'],
            '2F slab' => [60, '2026-03-02'], 'Handover' => [70, '2026-03-12'],
        ], Activity::where('building_id', $this->building->id)->orderBy('sort_order')->get()
            ->mapWithKeys(fn (Activity $a) => [$a->description => [$a->planned_day, $a->planned_date->toDateString()]])->all());
    }

    public function test_the_building_figure_is_the_workbooks_and_unchanged_by_floors(): void
    {
        $metrics = $this->progress()->metrics;

        $this->assertSame([70, 40, 40 / 70, -2, '2026-03-12'], [
            $metrics->totalPlannedDays, $metrics->daysCompleted, $metrics->progress, $metrics->currentLead, $metrics->plannedFinish,
        ]);
    }

    public function test_every_floor_figure_by_hand(): void
    {
        $this->assertSame([
            ['Ground floor', 0, 2, 2, 30, 30, 1.0, '2026-02-10', '2026-02-12', null, FloorStatus::Done],
            ['1st floor', 1, 2, 0, 25, 0, 0.0, '2026-03-07', null, '2026-03-09', FloorStatus::Behind],
            ['2nd floor', 2, 1, 0, 5, 0, 0.0, '2026-03-02', null, '2026-03-04', FloorStatus::NotStarted],
            ['Whole building', null, 2, 1, 15, 10, 10 / 15, '2026-03-12', null, '2026-03-14', FloorStatus::InProgress],
        ], $this->table($this->progress()->floors));

        $this->assertSame(
            [$this->floors['Ground floor']->id, $this->floors['1st floor']->id, $this->floors['2nd floor']->id, null],
            array_map(fn (FloorProgress $f) => $f->id, $this->progress()->floors),
        );
    }

    public function test_floor_work_days_need_not_add_up_to_the_buildings(): void
    {
        $floors = $this->progress()->floors;

        $this->assertSame(75, array_sum(array_map(fn (FloorProgress $f) => $f->workDays, $floors)));
        $this->assertSame(70, $this->progress()->metrics->totalPlannedDays);
    }

    public function test_the_project_figure_by_hand(): void
    {
        $service = app(ProgressService::class);
        $buildings = Building::where('project_id', $this->project->id)->orderBy('sort_order')->get()->map(fn (Building $b) => $service->building($b));

        $this->assertSame(0.7, $service->project($buildings));
        $this->assertSame(0.7, app(ProjectSummaryService::class)->summarise($this->project)->progress);
    }

    public function test_a_building_without_floors_has_no_floor_rows(): void
    {
        $tower2 = Building::where('name', 'Tower 2')->sole();

        $this->assertSame([], app(ProgressService::class)->building($tower2)->floors);
        $this->actingAs($this->owner())->get("/projects/{$this->project->id}/buildings/{$tower2->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->where('floors', []));
    }

    public function test_finishing_the_last_activity_on_a_floor_marks_it_done_with_its_actual_finish(): void
    {
        DB::table('activities')->where('id', $this->ids['2F slab'])->update(['actual_date' => '2026-02-28']);

        $second = $this->table($this->progress()->floors)[2];
        $this->assertSame(['2nd floor', 2, 1, 1, 5, 5, 1.0, '2026-03-02', '2026-02-28', null, FloorStatus::Done], $second);
    }

    /**
     * Deleting the 1st floor moves its two activities to the whole building: 10 + 15 + 10 + 5 = 40
     * work-days, 10 done, 1 of 4, and 1F slab is past its date, so Behind. The building is unchanged.
     */
    public function test_deleting_a_floor_moves_its_activities_to_the_whole_building(): void
    {
        $moved = app(FloorEditor::class)->delete($this->floors['1st floor'], $this->owner());

        $this->assertSame(2, $moved);
        $this->assertNull(Activity::find($this->ids['1F slab'])->floor_id);
        $this->assertNull(Activity::find($this->ids['1F walls'])->floor_id);
        $this->assertSoftDeleted($this->floors['1st floor']);

        $progress = $this->progress();
        $this->assertSame([
            ['Ground floor', 0, 2, 2, 30, 30, 1.0, '2026-02-10', '2026-02-12', null, FloorStatus::Done],
            ['2nd floor', 2, 1, 0, 5, 0, 0.0, '2026-03-02', null, '2026-03-04', FloorStatus::NotStarted],
            ['Whole building', null, 4, 1, 40, 10, 0.25, '2026-03-12', null, '2026-03-14', FloorStatus::Behind],
        ], $this->table($progress->floors));
        $this->assertSame([70, 40], [$progress->metrics->totalPlannedDays, $progress->metrics->daysCompleted]);
    }

    public function test_the_building_page_and_the_floors_api_carry_the_same_rows(): void
    {
        $expected = json_decode(json_encode($this->progress()->floors), true);
        $url = "/projects/{$this->project->id}/buildings/{$this->building->id}";

        $this->actingAs($this->owner())->get($url)->assertOk()
            ->assertInertia(fn ($page) => $page->where('floors', $expected)
                ->where('activities.0.floor_id', null)
                ->where('activities.1.floor_id', $this->floors['Ground floor']->id));
        $this->actingAs($this->owner())->getJson("{$url}/floors")->assertOk()->assertExactJson($expected);
        $this->assertSame('Behind', $expected[1]['status']);
    }

    private function progress(): BuildingProgress
    {
        return app(ProgressService::class)->building($this->building->fresh());
    }

    /**
     * @param  list<FloorProgress>  $floors
     * @return list<list<mixed>>
     */
    private function table(array $floors): array
    {
        return array_map(fn (FloorProgress $f) => [
            $f->name, $f->level, $f->activitiesCount, $f->doneCount, $f->workDays, $f->workDaysDone, $f->progress,
            $f->plannedFinish, $f->actualFinish, $f->forecastFinish, $f->status,
        ], $floors);
    }
}
