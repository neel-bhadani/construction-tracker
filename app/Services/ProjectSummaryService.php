<?php

namespace App\Services;

use App\Data\BuildingProgress;
use App\Data\BuildingSummary;
use App\Data\ProjectSummary;
use App\Enums\ActivityStatus;
use App\Models\Building;
use App\Models\Project;
use DateTimeInterface;

/**
 * The project and building level around the timelines. It only gathers and combines what
 * ProgressService already computes for each building and for the project.
 */
class ProjectSummaryService
{
    public function __construct(private readonly ProgressService $progress) {}

    public function summarise(Project $project, ?DateTimeInterface $today = null): ProjectSummary
    {
        $progress = [];
        $buildings = $project->buildings()->withCount('activities')->orderBy('sort_order')->orderBy('id')->get()
            ->map(function (Building $building) use ($today, &$progress) {
                $progress[] = $buildingProgress = $this->progress->building($building, $today);

                return $this->building($building, $buildingProgress);
            })
            ->all();

        $behind = collect($buildings)->whereNotNull('currentLead')->sortBy('currentLead')->first();
        $handovers = array_filter(array_column($buildings, 'expectedHandover'));

        return new ProjectSummary(
            id: $project->id,
            name: $project->name,
            details: $project->details,
            plannedStartDate: $project->planned_start_date->toDateString(),
            timezone: $project->timezone,
            buildingsCount: count($buildings),
            progress: $this->progress->project($progress),
            mostBehind: $behind === null ? null : ['id' => $behind->id, 'name' => $behind->name, 'lead' => $behind->currentLead],
            expectedHandover: $handovers === [] ? null : max($handovers),
            buildings: $buildings,
        );
    }

    private function building(Building $building, BuildingProgress $progress): BuildingSummary
    {
        $metrics = $progress->metrics;
        $next = collect($progress->projection->activities)->firstWhere('status', ActivityStatus::Next);

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
