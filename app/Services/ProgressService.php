<?php

namespace App\Services;

use App\Data\ActivityProjection;
use App\Data\BuildingProgress;
use App\Data\FloorProgress;
use App\Enums\FloorStatus;
use App\Models\Activity;
use App\Models\Building;
use App\Models\Floor;
use DateTimeInterface;
use Illuminate\Support\Collection;

/**
 * Progress at all three levels, all in work-days, so the levels agree with each other.
 *
 * - Building: the workbook's own figure, unchanged (BuildingMetricsService, C12): the furthest
 *   work-day reached by a done activity / the building's total work-days.
 * - Floor: the work-days of its done activities / all its work-days. An activity's work-days are
 *   its work-day minus that of the activity it comes after (at least 0); a break's are 0.
 * - Project: the buildings' work-days done / their total work-days.
 *
 * Floor percentages need not add up to the building's: the building measures how far along its
 * longest chain the work has reached, the floors add up each activity's own days, and the days of
 * breaks count on no floor.
 */
class ProgressService
{
    public function __construct(
        private readonly BuildingMetricsService $metrics,
        private readonly LeadProjectionService $leads,
    ) {}

    public function building(Building $building, ?DateTimeInterface $today = null): BuildingProgress
    {
        $metrics = $this->metrics->forBuilding($building, $today);
        $projection = $this->leads->project($metrics);

        return new BuildingProgress($metrics, $projection, $this->floors($building, $projection->activities, $today));
    }

    /**
     * @param  iterable<BuildingProgress>  $buildings
     * @return ?float null when no building has any work-days
     */
    public function project(iterable $buildings): ?float
    {
        $total = $done = 0;
        foreach ($buildings as $building) {
            $total += $building->metrics->totalPlannedDays;
            $done += $building->metrics->daysCompleted;
        }

        return $total === 0 ? null : $done / $total;
    }

    /**
     * @param  list<ActivityProjection>  $projected  the Lead Tracker rows, for actual-or-forecast
     * @return list<FloorProgress>
     */
    private function floors(Building $building, array $projected, ?DateTimeInterface $today): array
    {
        $floors = Floor::where('building_id', $building->id)->orderBy('level')->orderBy('id')->get();
        if ($floors->isEmpty()) {
            return [];
        }

        $activities = Activity::where('building_id', $building->id)->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'floor_id', 'predecessor_id', 'planned_day', 'planned_date', 'actual_date', 'is_break'])
            ->keyBy('id');
        $forecast = collect($projected)->pluck('actualOrForecast', 'id');
        $todayDay = ScheduleDays::currentDay($today, $building->project()->withTrashed()->value('timezone'));

        // breaks have no floor and no work-days; leaving them out keeps "3 of 5 done" honest
        $work = $activities->reject(fn (Activity $a) => $a->is_break)->map(fn (Activity $a) => (object) [
            'activity' => $a,
            'floor_id' => $a->floor_id,
            'days' => max(0, $a->planned_day - ($a->predecessor_id === null ? 0 : $activities[$a->predecessor_id]->planned_day)),
            'planned' => $a->planned_date->toDateString(),
            'actual' => $a->actual_date?->toDateString(),
            'forecast' => $forecast[$a->id] ?? null,
        ]);

        $rows = $floors->map(fn (Floor $floor) => $this->floor($floor->id, $floor->name, $floor->level, $work->where('floor_id', $floor->id), $todayDay));
        $rows->push($this->floor(null, 'Whole building', null, $work->whereNull('floor_id'), $todayDay));

        return $rows->values()->all();
    }

    /**
     * @param  Collection<int, object{activity: Activity, days: int, planned: string, actual: ?string, forecast: ?string}>  $rows
     */
    private function floor(?int $id, string $name, ?int $level, Collection $rows, int $today): FloorProgress
    {
        $done = $rows->whereNotNull('actual');
        $pending = $rows->whereNull('actual');
        $total = (int) $rows->sum('days');
        $allDone = $rows->isNotEmpty() && $pending->isEmpty();

        $status = match (true) {
            $allDone => FloorStatus::Done,
            $pending->contains(fn ($row) => ScheduleDays::toDay($row->planned) < $today) => FloorStatus::Behind,
            $done->isNotEmpty() => FloorStatus::InProgress,
            default => FloorStatus::NotStarted,
        };

        return new FloorProgress(
            id: $id,
            name: $name,
            level: $level,
            activitiesCount: $rows->count(),
            doneCount: $done->count(),
            workDays: $total,
            workDaysDone: (int) $done->sum('days'),
            progress: $total === 0 ? null : $done->sum('days') / $total,
            plannedFinish: $rows->max('planned'),
            actualFinish: $allDone ? $done->max('actual') : null,
            forecastFinish: $allDone || $pending->contains(fn ($row) => $row->forecast === null) ? null : $rows->max('forecast'),
            status: $status,
        );
    }
}
