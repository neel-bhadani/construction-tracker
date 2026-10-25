<?php

namespace App\Services;

use App\Data\BuildingSummary;
use App\Data\ProjectSummary;
use App\Enums\ActivityStatus;
use App\Models\Building;
use App\Models\Project;
use DateTimeInterface;

/**
 * The project and building level around the timelines. It only gathers and combines what
 * BuildingMetricsService and LeadProjectionService already compute for each building.
 */
class ProjectSummaryService
{
    public function __construct(
        private readonly BuildingMetricsService $metrics,
        private readonly LeadProjectionService $leads,
    ) {}

    public function summarise(Project $project, ?DateTimeInterface $today = null): ProjectSummary
    {
        $buildings = $project->buildings()->withCount('activities')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (Building $building) => $this->building($building, $today))
            ->all();

        $total = array_sum(array_column($buildings, 'totalPlannedDays'));
        $behind = collect($buildings)->whereNotNull('currentLead')->sortBy('currentLead')->first();
        $handovers = array_filter(array_column($buildings, 'expectedHandover'));

        return new ProjectSummary(
            id: $project->id,
            name: $project->name,
            details: $project->details,
            plannedStartDate: $project->planned_start_date->toDateString(),
            timezone: $project->timezone,
            buildingsCount: count($buildings),
            progress: $total === 0 ? null : array_sum(array_column($buildings, 'daysCompleted')) / $total,
            mostBehind: $behind === null ? null : ['id' => $behind->id, 'name' => $behind->name, 'lead' => $behind->currentLead],
            expectedHandover: $handovers === [] ? null : max($handovers),
            buildings: $buildings,
        );
    }

    private function building(Building $building, ?DateTimeInterface $today): BuildingSummary
    {
        $metrics = $this->metrics->forBuilding($building, $today);
        $next = collect($this->leads->project($metrics)->activities)->firstWhere('status', ActivityStatus::Next);

        return new BuildingSummary(
            id: $building->id,
            name: $building->name,
            type: $building->type->value,
            typeLabel: $building->type->label(),
            sortOrder: $building->sort_order,
            plannedStartDate: $building->planned_start_date->toDateString(),
            actualStartDate: $building->actual_start_date?->toDateString(),
            activitiesCount: $building->activities_count,
            daysCompleted: $metrics->daysCompleted,
            totalPlannedDays: $metrics->totalPlannedDays,
            progress: $metrics->progress,
            currentLead: $metrics->currentLead,
            next: $next?->description,
            expectedHandover: $metrics->forecastHoldLead ?? $metrics->plannedFinish,
        );
    }
}
