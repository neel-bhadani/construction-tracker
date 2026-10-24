<?php

namespace Tests\Feature;

use App\Data\LeadProjection;
use App\Data\ScopeMetrics;
use App\Models\Project;
use App\Models\Scope;
use App\Services\LeadProjectionService;
use App\Services\ScheduleRecalculator;
use App\Services\ScopeMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cases the three workbooks never exercise: their actuals are all in row order and few. Planned
 * start is 2026-04-05, so planned day N is 2026-04-05 + N.
 */
class ScopeMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Scope $scope;

    private int $sortOrder = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $project = Project::create(['name' => 'Test project', 'planned_start_date' => '2026-04-05']);
        $this->scope = Scope::create(['project_id' => $project->id, 'name' => 'Test scope']);
        $this->travelTo('2026-10-15');
    }

    /**
     * Row order and date order disagree here: E is the earliest pending activity by date but the
     * last by row. NEXT follows row order, COUNTBLANK(E$4:E_row)=1.
     */
    public function test_next_is_the_first_blank_row_in_row_order_not_date_order(): void
    {
        $a = $this->activity('A', null, 10, actual: '2026-04-15');
        $this->activity('B', $a, 50);
        $c = $this->activity('C', $a, 5, actual: '2026-04-21');
        $this->activity('D', $c, 5);
        $this->activity('E', $a, 2);

        $this->assertSame(
            ['A' => 'Done', 'B' => 'NEXT', 'C' => 'Done', 'D' => 'Pending', 'E' => 'Pending'],
            $this->statuses($this->projection()),
        );
    }

    public function test_out_of_order_actual_drives_progress_from_the_furthest_completed_row(): void
    {
        $a = $this->activity('A', null, 10);
        $b = $this->activity('B', $a, 10);
        $this->activity('C', $b, 80, actual: '2026-06-01');

        $metrics = $this->metrics();

        $this->assertSame(100, $metrics->daysCompleted);
        $this->assertSame(1.0, $metrics->progress);
        $this->assertSame(['A' => 'NEXT', 'B' => 'Pending', 'C' => 'Done'], $this->statuses($this->projection()));
    }

    public function test_breaks_count_toward_progress(): void
    {
        $a = $this->activity('A', null, 10, actual: '2026-04-15');
        $this->activity('DIWALI BREAK', $a, 20, isBreak: true, actual: '2026-05-05');
        $this->activity('Z', $a, 60);

        $this->assertSame(30, $this->metrics()->daysCompleted);
    }

    public function test_current_lead_uses_the_first_row_sharing_the_latest_actual(): void
    {
        $a = $this->activity('A', null, 10, actual: '2026-04-15');
        $this->activity('B', $a, 20, actual: '2026-04-30');
        $this->activity('C', $a, 10, actual: '2026-04-30');

        $metrics = $this->metrics();

        $this->assertSame(5, $metrics->currentLead);
        $this->assertSame('2026-04-30', $metrics->latestActual);
    }

    public function test_recent_pace_uses_the_milestone_strictly_before_the_latest(): void
    {
        $a = $this->activity('A', null, 10, actual: '2026-04-13');
        $b = $this->activity('B', $a, 20, actual: '2026-05-03');
        $this->activity('C', $b, 10, actual: '2026-05-03');
        $this->activity('D', $b, 30, actual: '2026-05-13');
        $this->activity('E', $b, 100);

        $metrics = $this->metrics();

        $this->assertSame('2026-05-03', $metrics->previousActual);
        $this->assertSame(40, $metrics->daysCompletedAtPrevious);
        $this->assertEqualsWithDelta(2.0, $metrics->recentPace, 1e-12);
        $this->assertEqualsWithDelta(60 / 38, $metrics->averagePace, 1e-12);
    }

    public function test_recent_pace_falls_back_to_average_pace_with_a_single_actual_date(): void
    {
        $a = $this->activity('A', null, 10, actual: '2026-04-13');
        $this->activity('B', $a, 10, actual: '2026-04-13');
        $this->activity('C', $a, 100);

        $metrics = $this->metrics();

        $this->assertNull($metrics->previousActual);
        $this->assertNull($metrics->daysCompletedAtPrevious);
        $this->assertEqualsWithDelta(20 / 8, $metrics->averagePace, 1e-12);
        $this->assertSame($metrics->averagePace, $metrics->recentPace);
    }

    /**
     * The furthest-day row was finished before the latest one, so no planned days were gained
     * between the last two milestones. Recent pace is 0 before the floor; it cannot go negative,
     * because days completed is a maximum over a superset of the previous milestone's rows.
     */
    public function test_recent_pace_is_floored_when_an_out_of_order_actual_gains_no_days(): void
    {
        $a = $this->activity('A', null, 10);
        $this->activity('B', $a, 100, actual: '2026-05-25');
        $this->activity('C', $a, 5, actual: '2026-06-04');
        $this->activity('D', $a, 200);

        $metrics = $this->metrics();

        $this->assertSame(0.01, $metrics->recentPace);
        $this->assertEqualsWithDelta(110 / 60, $metrics->averagePace, 1e-12);
        $this->assertSame('2026-06-04', $metrics->latestActual);
        $this->assertSame(-45, $metrics->currentLead);
        $this->assertSame(now()->parse('2026-06-04')->addDays(10000)->toDateString(), $metrics->forecastRecentPace);
    }

    public function test_average_pace_is_floored_when_no_planned_days_are_complete(): void
    {
        $this->activity('A', null, 0, actual: '2026-04-25');
        $this->activity('B', null, 10);

        $this->assertSame(0.01, $this->metrics()->averagePace);
    }

    public function test_average_pace_is_one_when_the_latest_actual_is_not_after_the_actual_start(): void
    {
        $a = $this->activity('A', null, 0, actual: '2026-04-05');
        $this->activity('B', $a, 10);

        $metrics = $this->metrics();

        $this->assertSame(1.0, $metrics->averagePace);
        $this->assertSame('2026-04-15', $metrics->forecastAveragePace);
    }

    /**
     * One remaining day at pace 2 is half a day: Excel's ROUND gives 1, banker's rounding would give 0.
     */
    public function test_forecasts_round_half_away_from_zero(): void
    {
        $a = $this->activity('A', null, 4, actual: '2026-04-07');
        $this->activity('B', $a, 1);

        $metrics = $this->metrics();

        $this->assertSame(2.0, $metrics->averagePace);
        $this->assertSame('2026-04-08', $metrics->forecastAveragePace);
    }

    public function test_what_if_lead_replaces_the_current_lead_only_when_supplied(): void
    {
        $a = $this->activity('A', null, 10, actual: '2026-04-10');
        $this->activity('B', $a, 20);

        $current = $this->projection();
        $zero = $this->projection(0);
        $behind = $this->projection(-3);

        $this->assertSame([5, null, 5, '2026-04-30'], [$current->currentLead, $current->whatIfLead, $current->appliedLead, $current->forecastFinish]);
        $this->assertSame([5, 0, 0, '2026-05-05'], [$zero->currentLead, $zero->whatIfLead, $zero->appliedLead, $zero->forecastFinish]);
        $this->assertSame('2026-05-05', $zero->activities[1]->actualOrForecast);
        $this->assertSame(-3, $behind->activities[1]->leadApplied);
        $this->assertSame('2026-05-08', $behind->activities[1]->actualOrForecast);
        $this->assertSame(5, $behind->activities[0]->leadApplied);
    }

    /**
     * Excel shows #N/A or nonsense dates here. Nothing is invented.
     */
    public function test_scope_without_actual_dates_reports_null_rather_than_inventing_figures(): void
    {
        $a = $this->activity('A', null, 10);
        $this->activity('B', $a, 20);

        $metrics = $this->metrics();
        $projection = $this->projection();

        $this->assertSame([0, 0.0, '2026-05-05'], [$metrics->daysCompleted, $metrics->progress, $metrics->plannedFinish]);
        foreach (['latestActual', 'currentLead', 'daysSinceLatestActual', 'averagePace', 'recentPace',
            'forecastAveragePace', 'forecastRecentPace', 'forecastHoldLead', 'aheadHoldLead'] as $figure) {
            $this->assertNull($metrics->{$figure}, $figure);
        }
        $this->assertNull($metrics->activities[1]->forecastAveragePace);
        $this->assertSame([null, null], [$projection->appliedLead, $projection->forecastFinish]);
        $this->assertSame(['A' => 'NEXT', 'B' => 'Pending'], $this->statuses($projection));
        $this->assertSame('2026-05-03', $this->projection(2)->forecastFinish);
    }

    public function test_scope_without_activities_is_not_an_error(): void
    {
        $metrics = $this->metrics();

        $this->assertSame([0, null, 0.0, []], [$metrics->totalPlannedDays, $metrics->plannedFinish, $metrics->progress, $metrics->activities]);
        $this->assertNull($this->projection(5)->forecastFinish);
    }

    private function activity(string $description, ?int $predecessorId, int $offset, ?string $actual = null, bool $isBreak = false): int
    {
        $id = DB::table('activities')->insertGetId([
            'scope_id' => $this->scope->id,
            'description' => $description,
            'predecessor_id' => $predecessorId,
            'offset_days' => $offset,
            'is_break' => $isBreak,
            'planned_date' => '2026-04-05',
            'actual_date' => $actual,
            'sort_order' => ++$this->sortOrder,
        ]);
        app(ScheduleRecalculator::class)->recalculate($this->scope);

        return $id;
    }

    private function metrics(): ScopeMetrics
    {
        return app(ScopeMetricsService::class)->forScope($this->scope);
    }

    private function projection(?int $whatIfLead = null): LeadProjection
    {
        return app(LeadProjectionService::class)->project($this->metrics(), $whatIfLead);
    }

    /**
     * @return array<string, string>
     */
    private function statuses(LeadProjection $projection): array
    {
        return collect($projection->activities)
            ->mapWithKeys(fn ($row) => [$row->description => $row->status->value])
            ->all();
    }
}
