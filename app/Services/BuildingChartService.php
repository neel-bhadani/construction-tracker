<?php

namespace App\Services;

use App\Data\ActivityMetrics;
use App\Data\BuildingMetrics;
use App\Models\Building;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * The client's progress chart: the Calc sheet behind each workbook's Chart sheet, computed on
 * request and never stored. Every figure it starts from — total days, progress, lead, the three
 * forecasts, the actual start — is BuildingMetricsService output.
 *
 * rows() is the Calc sheet itself, row for row, duplicates included; the oracle compares it with
 * Excel's cached values. chart() is what the page draws.
 */
class BuildingChartService
{
    public function __construct(private readonly BuildingMetricsService $metrics) {}

    /**
     * The Calc sheet's columns. Each point is [date, fraction, activity description or null].
     *
     * planned      A/B  (planned start, 0), then each planned date in date order with
     *                   MAX(day of rows planned on or before it) / total
     * actual       D/E  (actual start, 0), then one row per activity: the k-th actual date in date
     *                   order, the latest once they run out, the actual start if there are none;
     *                   MAX(day of rows finished on or before it) / total
     * averagePace  G/H  (latest actual, progress) -> (average-pace forecast, 100%)
     * recentPace   J/K  (latest actual, progress) -> (recent-pace forecast, 100%)
     * holdLead     M/N  (latest actual, progress), then per planned row
     *                   (MAX(planned date - lead, latest actual), MAX(planned %, progress))
     *
     * The three projections are null until there is an actual date: Excel shows #N/A.
     *
     * @return array{planned: list<array{string, float, ?string}>, actual: list<array{string, float, ?string}>, averagePace: ?list<array{string, float, ?string}>, recentPace: ?list<array{string, float, ?string}>, holdLead: ?list<array{string, float, ?string}>}
     */
    public function rows(Building $building, ?DateTimeInterface $today = null): array
    {
        return $this->calc($building, $this->metrics->forBuilding($building, $today));
    }

    /**
     * What the building page draws. Consecutive identical points are merged, their activities
     * listed together: the line is the same, and one dot has one tooltip. With no actual date,
     * the actual line and the projections are left out.
     *
     * @return array<string, mixed>
     */
    public function chart(Building $building, ?DateTimeInterface $today = null): array
    {
        $metrics = $this->metrics->forBuilding($building, $today);
        $rows = $this->calc($building, $metrics);
        $hasActuals = $metrics->latestActual !== null;
        $plannedStart = $building->planned_start_date->toDateString();
        $plannedFinish = $metrics->plannedFinish ?? $plannedStart;

        $series = ['planned' => $this->merge($rows['planned'])];
        foreach (['actual', 'averagePace', 'recentPace', 'holdLead'] as $name) {
            $series[$name] = $hasActuals ? $this->merge($rows[$name]) : null;
        }

        // the axis reaches every forecast, ticked or not, so it does not jump; capped at a year past plan
        $latest = max(array_filter([$plannedFinish, $metrics->forecastAveragePace, $metrics->forecastRecentPace, $metrics->forecastHoldLead]));
        $end = min(
            CarbonImmutable::parse($latest)->addMonthNoOverflow()->toDateString(),
            CarbonImmutable::parse($plannedFinish)->addYearNoOverflow()->toDateString(),
        );

        return [
            'hasActivities' => $metrics->activities !== [],
            'hasActuals' => $hasActuals,
            'series' => $series,
            'finishes' => [
                'planned' => $metrics->plannedFinish,
                'averagePace' => $metrics->forecastAveragePace,
                'recentPace' => $metrics->forecastRecentPace,
                'holdLead' => $metrics->forecastHoldLead,
            ],
            'today' => ScheduleDays::toDate(ScheduleDays::currentDay($today, $building->project()->withTrashed()->value('timezone'))),
            'ranges' => [
                'full' => ['start' => $plannedStart, 'end' => max($end, $plannedStart)],
                'firstYear' => ['start' => $plannedStart, 'end' => CarbonImmutable::parse($plannedStart)->addMonthsNoOverflow(12)->toDateString()],
            ],
        ];
    }

    /**
     * @return array{planned: list<array{string, float, ?string}>, actual: list<array{string, float, ?string}>, averagePace: ?list<array{string, float, ?string}>, recentPace: ?list<array{string, float, ?string}>, holdLead: ?list<array{string, float, ?string}>}
     */
    private function calc(Building $building, BuildingMetrics $metrics): array
    {
        $total = $metrics->totalPlannedDays;
        $share = fn (int $day) => $total === 0 ? 0.0 : $day / (float) $total;
        $activities = $metrics->activities;

        // A/B: SMALL over the planned dates, ties in row order
        $byPlannedDate = $this->sorted($activities, fn (ActivityMetrics $a) => $a->plannedDate);
        $reachedBy = fn (string $date) => max([0, ...array_map(
            fn (ActivityMetrics $a) => $a->plannedDay,
            array_filter($activities, fn (ActivityMetrics $a) => $a->plannedDate <= $date),
        )]);
        $planned = [[$building->planned_start_date->toDateString(), 0.0, null]];
        foreach ($byPlannedDate as $activity) {
            $planned[] = [$activity->plannedDate, $share($reachedBy($activity->plannedDate)), $activity->description];
        }

        // D/E: SMALL over the actual dates, then the latest repeated
        $done = $this->sorted(array_filter($activities, fn (ActivityMetrics $a) => $a->actualDate !== null), fn (ActivityMetrics $a) => $a->actualDate);
        $finishedBy = fn (string $date) => max([0, ...array_map(
            fn (ActivityMetrics $a) => $a->plannedDay,
            array_filter($done, fn (ActivityMetrics $a) => $a->actualDate <= $date),
        )]);
        $actual = [[$metrics->actualStartDate, 0.0, null]];
        foreach (array_keys($activities) as $k) {
            [$date, $description] = match (true) {
                $done === [] => [$metrics->actualStartDate, null],
                isset($done[$k]) => [$done[$k]->actualDate, $done[$k]->description],
                default => [$metrics->latestActual, null],
            };
            $actual[] = [$date, $share($done === [] ? 0 : $finishedBy($date)), $description];
        }

        if ($metrics->latestActual === null) {
            return ['planned' => $planned, 'actual' => $actual, 'averagePace' => null, 'recentPace' => null, 'holdLead' => null];
        }

        $start = [$metrics->latestActual, $metrics->progress, null];

        // M/N: each planned point moved back by the lead, never before the latest actual
        $latest = ScheduleDays::toDay($metrics->latestActual);
        $holdLead = [$start];
        foreach (array_slice($planned, 1) as [$date, $fraction, $description]) {
            $holdLead[] = [
                ScheduleDays::toDate(max(ScheduleDays::toDay($date) - $metrics->currentLead, $latest)),
                max($fraction, $metrics->progress),
                $description,
            ];
        }

        return [
            'planned' => $planned,
            'actual' => $actual,
            'averagePace' => [$start, [$metrics->forecastAveragePace, 1.0, null]],
            'recentPace' => [$start, [$metrics->forecastRecentPace, 1.0, null]],
            'holdLead' => $holdLead,
        ];
    }

    /**
     * @param  array<int, ActivityMetrics>  $activities
     * @return list<ActivityMetrics> by the given date, ties kept in row order
     */
    private function sorted(array $activities, callable $date): array
    {
        $activities = array_values($activities);
        $order = array_keys($activities);
        usort($order, fn (int $a, int $b) => [$date($activities[$a]), $a] <=> [$date($activities[$b]), $b]);

        return array_map(fn (int $index) => $activities[$index], $order);
    }

    /**
     * @param  list<array{string, float, ?string}>  $points
     * @return list<array{date: string, percent: float, activities: list<string>}>
     */
    private function merge(array $points): array
    {
        $merged = [];

        foreach ($points as [$date, $fraction, $description]) {
            $last = array_key_last($merged);

            if ($last !== null && $merged[$last]['date'] === $date && abs($merged[$last]['percent'] - $fraction) < 1e-12) {
                if ($description !== null && ! in_array($description, $merged[$last]['activities'], true)) {
                    $merged[$last]['activities'][] = $description;
                }

                continue;
            }

            $merged[] = ['date' => $date, 'percent' => $fraction, 'activities' => $description === null ? [] : [$description]];
        }

        return $merged;
    }
}
