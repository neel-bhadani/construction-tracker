<?php

namespace App\Services;

use App\Data\BuildingProgress;
use App\Models\Building;
use DateTimeInterface;

/**
 * Progress at the building and project level, both in work-days, so the two agree.
 *
 * - Building: the workbook's own figure, unchanged (BuildingMetricsService, C12): the furthest
 *   work-day reached by a done activity / the building's total work-days.
 * - Project: the buildings' work-days done / their total work-days.
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

        return new BuildingProgress($metrics, $this->leads->project($metrics));
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
}
