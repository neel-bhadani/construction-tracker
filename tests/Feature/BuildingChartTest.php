<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Building;
use App\Models\Project;
use App\Models\User;
use App\Services\BuildingChartService;
use App\Services\ScheduleRecalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The progress chart on a plan small enough to work out by hand. Start 1 Jan 2026:
 *
 *   A  root, +10            day 10   planned 11 Jan
 *   B  after A, +20         day 30   planned 31 Jan
 *   C  after A, +5, adj +3  day 15   planned 19 Jan (16 Jan + 3)
 *   D  after B, +10         day 40   planned 10 Feb
 *
 * Total 40 days. In planned-date order A, C, B, D reach 10, 15, 30 and 40 days: 25%, 37.5%, 75%, 100%.
 */
class BuildingChartTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Building $building;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-10-15 12:00');
        $this->project = Project::create(['name' => 'Hand-made', 'planned_start_date' => '2026-01-01']);
        $this->building = Building::create(['project_id' => $this->project->id, 'name' => 'Block 1']);

        foreach ([['A', null, 10, 0], ['B', 'A', 20, 0], ['C', 'A', 5, 3], ['D', 'B', 10, 0]] as $order => [$name, $after, $offset, $adjustment]) {
            $this->ids[$name] = DB::table('activities')->insertGetId([
                'building_id' => $this->building->id, 'description' => $name, 'predecessor_id' => $after ? $this->ids[$after] : null,
                'offset_days' => $offset, 'date_adjustment_days' => $adjustment, 'adjustment_reason' => $adjustment ? 'test' : null,
                'planned_date' => '2026-01-01', 'sort_order' => $order + 1,
            ]);
        }
        app(ScheduleRecalculator::class)->recalculate($this->building);
    }

    public function test_planned_series_follows_the_planned_dates_in_date_order(): void
    {
        $this->assertSame([
            ['2026-01-01', 0.0, null],
            ['2026-01-11', 0.25, 'A'],
            ['2026-01-19', 0.375, 'C'],
            ['2026-01-31', 0.75, 'B'],
            ['2026-02-10', 1.0, 'D'],
        ], $this->rows()['planned']);
    }

    /** Excel's Calc sheet: the actual start repeated at 0%, and #N/A for the projections. */
    public function test_with_no_actuals_the_rows_keep_excels_start_point_and_the_chart_shows_only_planned(): void
    {
        $rows = $this->rows();

        $this->assertSame(array_fill(0, 5, ['2026-01-01', 0.0, null]), $rows['actual']);
        $this->assertSame([null, null, null], [$rows['averagePace'], $rows['recentPace'], $rows['holdLead']]);

        $chart = $this->chart();
        $this->assertSame([true, false], [$chart['hasActivities'], $chart['hasActuals']]);
        $this->assertCount(5, $chart['series']['planned']);
        $this->assertSame([null, null, null, null], [$chart['series']['actual'], $chart['series']['averagePace'], $chart['series']['recentPace'], $chart['series']['holdLead']]);
        $this->assertSame('2026-10-15', $chart['today']);
        // no forecasts yet: planned finish 10 Feb + 1 month
        $this->assertSame(['start' => '2026-01-01', 'end' => '2026-03-10'], $chart['ranges']['full']);
        $this->assertSame(['start' => '2026-01-01', 'end' => '2027-01-01'], $chart['ranges']['firstYear']);
    }

    /**
     * A finished 9 Jan, 2 days early. Progress 10/40 = 25%. Average pace 10 / 8 days = 1.25; with
     * one actual, recent pace falls back to it. Forecast 9 Jan + round(30 / 1.25) = 2 Feb. Hold
     * lead: every planned date 2 days earlier, never before 9 Jan, and never below 25%.
     */
    public function test_one_actual(): void
    {
        $this->actual('A', '2026-01-09');
        $rows = $this->rows();

        $this->assertSame([
            ['2026-01-01', 0.0, null],
            ['2026-01-09', 0.25, 'A'],
            ['2026-01-09', 0.25, null],
            ['2026-01-09', 0.25, null],
            ['2026-01-09', 0.25, null],
        ], $rows['actual']);
        $this->assertSame([['2026-01-09', 0.25, null], ['2026-02-02', 1.0, null]], $rows['averagePace']);
        $this->assertSame($rows['averagePace'], $rows['recentPace']);
        $this->assertSame([
            ['2026-01-09', 0.25, null],
            ['2026-01-09', 0.25, 'A'],
            ['2026-01-17', 0.375, 'C'],
            ['2026-01-29', 0.75, 'B'],
            ['2026-02-08', 1.0, 'D'],
        ], $rows['holdLead']);

        // drawn: the repeated rows collapse onto one dot that names its activity
        $chart = $this->chart();
        $this->assertSame([
            ['date' => '2026-01-01', 'percent' => 0.0, 'activities' => []],
            ['date' => '2026-01-09', 'percent' => 0.25, 'activities' => ['A']],
        ], $chart['series']['actual']);
        $this->assertSame(['date' => '2026-01-09', 'percent' => 0.25, 'activities' => ['A']], $chart['series']['holdLead'][0]);
        $this->assertCount(4, $chart['series']['holdLead']);
        $this->assertSame(['planned' => '2026-02-10', 'averagePace' => '2026-02-02', 'recentPace' => '2026-02-02', 'holdLead' => '2026-02-08'], $chart['finishes']);
        $this->assertSame('2026-03-10', $chart['ranges']['full']['end']);
    }

    /**
     * A on 9 Jan; B early and C late, both on 20 Jan. Progress is B's 30 days: 75%. The lead is
     * B's — the first row in row order finishing on the latest date — 31 Jan − 20 Jan = 11 days.
     * Recent pace (30 − 10) / 11 days; forecast 20 Jan + round(10 / (20/11)) = round(5.5) = 6 → 26 Jan.
     * Hold lead: A, C and B all clamp to (20 Jan, 75%); D is 10 Feb − 11 = 30 Jan.
     */
    public function test_two_actual_dates_with_a_tie_and_the_hold_lead_clamp(): void
    {
        $this->actual('A', '2026-01-09');
        $this->actual('B', '2026-01-20');
        $this->actual('C', '2026-01-20');
        $rows = $this->rows();

        $this->assertSame([
            ['2026-01-01', 0.0, null],
            ['2026-01-09', 0.25, 'A'],
            ['2026-01-20', 0.75, 'B'],
            ['2026-01-20', 0.75, 'C'],
            ['2026-01-20', 0.75, null],
        ], $rows['actual']);
        $this->assertSame(['2026-01-26', 1.0, null], $rows['recentPace'][1]);
        $this->assertSame(['2026-01-26', 1.0, null], $rows['averagePace'][1]);
        $this->assertSame([
            ['2026-01-20', 0.75, null],
            ['2026-01-20', 0.75, 'A'],
            ['2026-01-20', 0.75, 'C'],
            ['2026-01-20', 0.75, 'B'],
            ['2026-01-30', 1.0, 'D'],
        ], $rows['holdLead']);

        $chart = $this->chart();
        $this->assertSame([
            ['date' => '2026-01-20', 'percent' => 0.75, 'activities' => ['A', 'C', 'B']],
            ['date' => '2026-01-30', 'percent' => 1.0, 'activities' => ['D']],
        ], $chart['series']['holdLead']);
        $this->assertSame(['date' => '2026-01-20', 'percent' => 0.75, 'activities' => ['B', 'C']], $chart['series']['actual'][2]);
    }

    /**
     * A finished on 1 Jun, very late: average pace 10 / 151 days, forecast 1 Jun + round(30 × 15.1)
     * = 28 Aug 2027. The axis stops a year after the planned finish, 10 Feb 2027; the forecast date
     * itself is still reported.
     */
    public function test_the_axis_stops_a_year_after_the_planned_finish(): void
    {
        $this->actual('A', '2026-06-01');
        $chart = $this->chart();

        $this->assertSame('2027-08-28', $chart['finishes']['averagePace']);
        $this->assertSame('2027-02-10', $chart['ranges']['full']['end']);
        $this->assertSame(['date' => '2027-08-28', 'percent' => 1.0, 'activities' => []], $chart['series']['averagePace'][1]);
    }

    public function test_a_building_with_no_activities_has_nothing_to_draw(): void
    {
        $empty = Building::create(['project_id' => $this->project->id, 'name' => 'Block 2']);
        $chart = app(BuildingChartService::class)->chart($empty);

        $this->assertSame([false, false], [$chart['hasActivities'], $chart['hasActuals']]);
        $this->assertSame([['date' => '2026-01-01', 'percent' => 0.0, 'activities' => []]], $chart['series']['planned']);
    }

    /* ---------------- endpoint and page ---------------- */

    public function test_every_role_can_read_the_chart_and_it_is_the_services_output(): void
    {
        $url = "/projects/{$this->project->id}/buildings/{$this->building->id}/chart";

        foreach (['viewer', 'editor', 'admin'] as $role) {
            $this->actingAs(User::factory()->role(Role::from($role))->create())->getJson($url)
                ->assertOk()
                ->assertExactJson(json_decode(json_encode($this->chart()), true));
        }
    }

    public function test_the_chart_endpoint_refuses_guests_the_unapproved_and_the_wrong_project(): void
    {
        $url = "/projects/{$this->project->id}/buildings/{$this->building->id}/chart";
        $other = Project::create(['name' => 'Other', 'planned_start_date' => '2026-01-01']);

        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->unapproved()->create())->get($url)->assertRedirect('/login');
        $this->actingAs(User::factory()->create())->getJson("/projects/{$other->id}/buildings/{$this->building->id}/chart")->assertNotFound();
    }

    /** The page carries the chart, so recording an actual — a normal Inertia visit — redraws it. */
    public function test_the_building_page_carries_the_chart_and_recording_an_actual_changes_it(): void
    {
        $editor = User::factory()->editor()->create();
        $page = "/projects/{$this->project->id}/buildings/{$this->building->id}";

        $this->actingAs($editor)->get($page)
            ->assertInertia(fn (Assert $p) => $p->where('chart.hasActuals', false)->where('chart.series.actual', null));

        $this->actingAs($editor)->from($page)->patch("/activities/{$this->ids['A']}/actual", ['actual_date' => '2026-01-09'])->assertRedirect($page);

        $this->actingAs($editor)->get($page)
            ->assertInertia(fn (Assert $p) => $p->where('chart.hasActuals', true)->where('chart.series.actual.1.activities', ['A']));

        $this->actingAs($editor)->from($page)->patch("/activities/{$this->ids['A']}/actual", ['actual_date' => null]);

        $this->actingAs($editor)->get($page)->assertInertia(fn (Assert $p) => $p->where('chart.hasActuals', false));
    }

    private function actual(string $name, string $date): void
    {
        DB::table('activities')->where('id', $this->ids[$name])->update(['actual_date' => $date]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rows(): array
    {
        return app(BuildingChartService::class)->rows($this->building->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    private function chart(): array
    {
        return app(BuildingChartService::class)->chart($this->building->fresh());
    }
}
