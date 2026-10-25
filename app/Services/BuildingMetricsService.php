<?php

namespace App\Services;

use App\Data\ActivityMetrics;
use App\Data\BuildingMetrics;
use App\Models\Activity;
use App\Models\Building;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * The workbook's Chart sheet: status, pace and the three forecasts. Each figure follows the
 * client's formula, cell reference noted, not an improved version of it.
 *
 * Pace forecasts read planned_day and ignore date adjustments; the hold-lead forecast reads
 * planned_date and includes them. The two disagree on a nudged row by exactly the nudge.
 */
class BuildingMetricsService
{
    private const PACE_FLOOR = 0.01;

    public function forBuilding(Building $building, ?DateTimeInterface $today = null): BuildingMetrics
    {
        $project = $building->project()->withTrashed()->firstOrFail();
        $activities = $building->activities()->orderBy('sort_order')->orderBy('id')->get();
        $start = ScheduleDays::toDay($building->actual_start_date ?? $building->planned_start_date);

        return $this->calculate($activities, $start, ScheduleDays::currentDay($today, $project->timezone));
    }

    /**
     * @param  Collection<int, Activity>  $activities  in row order
     */
    private function calculate(Collection $activities, int $start, int $today): BuildingMetrics
    {
        $rows = $activities->map(fn (Activity $activity) => (object) [
            'activity' => $activity,
            'day' => $activity->planned_day,
            'date' => ScheduleDays::toDay($activity->planned_date),
            'actual' => $activity->actual_date === null ? null : ScheduleDays::toDay($activity->actual_date),
        ]);
        $done = $rows->whereNotNull('actual');

        $total = (int) $rows->max('day');                                            // C8
        $plannedFinish = $rows->max('date');                                          // C9
        $latest = $done->max('actual');                                               // C10
        $daysCompleted = (int) $done->max('day');                                     // C11, breaks included
        $progress = $total === 0 ? 0.0 : $daysCompleted / $total;                     // C12

        if ($latest === null) {
            return new BuildingMetrics(
                ScheduleDays::toDate($start), $total, $this->date($plannedFinish), null, 0, $progress,
                null, null, null, null, null, null, null, null, null, null, null, null,
                $rows->map(fn ($row) => $this->activityMetrics($row, null, null, null, null, null))->values()->all(),
            );
        }

        $lead = $done->firstWhere('actual', $latest)->date - $latest;                 // C13, first match in row order
        $averagePace = max($latest > $start ? $daysCompleted / ($latest - $start) : 1, self::PACE_FLOOR); // C17

        $previous = $done->where('actual', '<', $latest)->max('actual');              // C18
        $daysAtPrevious = $previous === null ? null : (int) $done->where('actual', '<=', $previous)->max('day'); // C19
        $recentPace = $previous === null                                              // C20
            ? $averagePace
            : max(($daysCompleted - $daysAtPrevious) / ($latest - $previous), self::PACE_FLOOR);

        $averageFinish = $latest + (int) round(($total - $daysCompleted) / $averagePace); // C25
        $recentFinish = $latest + (int) round(($total - $daysCompleted) / $recentPace);   // C27
        $holdLeadFinish = $plannedFinish - $lead;                                          // C29

        return new BuildingMetrics(
            actualStartDate: ScheduleDays::toDate($start),
            totalPlannedDays: $total,
            plannedFinish: ScheduleDays::toDate($plannedFinish),
            latestActual: ScheduleDays::toDate($latest),
            daysCompleted: $daysCompleted,
            progress: $progress,
            currentLead: $lead,
            daysSinceLatestActual: $today - $latest,                                  // C14
            averagePace: $averagePace,
            previousActual: $this->date($previous),
            daysCompletedAtPrevious: $daysAtPrevious,
            recentPace: $recentPace,
            forecastAveragePace: ScheduleDays::toDate($averageFinish),
            aheadAveragePace: $plannedFinish - $averageFinish,                        // C26
            forecastRecentPace: ScheduleDays::toDate($recentFinish),
            aheadRecentPace: $plannedFinish - $recentFinish,                          // C28
            forecastHoldLead: ScheduleDays::toDate($holdLeadFinish),
            aheadHoldLead: $plannedFinish - $holdLeadFinish,                          // C30
            activities: $rows->map(fn ($row) => $this->activityMetrics(
                $row, $latest, $daysCompleted, $averagePace, $recentPace, $lead,
            ))->values()->all(),
        );
    }

    /**
     * Chart sheet columns C to H. A completed row shows its actual date in every forecast column.
     * Pending rows already overtaken by the progress figure are forecast at the latest actual.
     */
    private function activityMetrics(object $row, ?int $latest, ?int $daysCompleted, ?float $averagePace, ?float $recentPace, ?int $lead): ActivityMetrics
    {
        $paceForecast = fn (?float $pace) => match (true) {
            $row->actual !== null => ScheduleDays::toDate($row->actual),
            $pace === null => null,
            default => ScheduleDays::toDate($latest + (int) round(max(0, $row->day - $daysCompleted) / $pace)),
        };

        return new ActivityMetrics(
            id: $row->activity->id,
            description: $row->activity->description,
            plannedDay: $row->day,
            plannedDate: ScheduleDays::toDate($row->date),
            actualDate: $this->date($row->actual),
            aheadDays: $row->actual === null ? null : $row->date - $row->actual,
            forecastAveragePace: $paceForecast($averagePace),
            forecastRecentPace: $paceForecast($recentPace),
            forecastHoldLead: match (true) {
                $row->actual !== null => ScheduleDays::toDate($row->actual),
                $lead === null => null,
                default => ScheduleDays::toDate($row->date - $lead),
            },
        );
    }

    private function date(?int $day): ?string
    {
        return $day === null ? null : ScheduleDays::toDate($day);
    }
}
