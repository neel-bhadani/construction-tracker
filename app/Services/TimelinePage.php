<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Building;
use App\Models\Project;
use App\Models\SubActivity;

/**
 * What a timeline screen shows, for a building's page and for the project's Common activities
 * alike: every figure is ProgressService output (BuildingMetricsService and LeadProjectionService)
 * passed through as it is.
 */
class TimelinePage
{
    public function __construct(
        private readonly ProgressService $progress,
        private readonly BuildingChartService $charts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function props(Project $project, Building $building): array
    {
        $buildingProgress = $this->progress->building($building);

        return [
            'building' => [
                'id' => $building->id,
                'name' => $building->name,
                'type' => $building->type->value,
                'planned_start_date' => $building->planned_start_date->toDateString(),
                'actual_start_date' => $building->actual_start_date?->toDateString(),
            ],
            'metrics' => $buildingProgress->metrics,
            'projection' => $buildingProgress->projection,
            // the progress chart; a page prop, so recording an actual redraws it with the rest
            'chart' => $this->charts->chart($building),
            // the stored fields the edit form starts from; derived figures come from the services.
            // planned_day is what the dialog turns a typed planned date into an offset with.
            // progress is the row's bar: its sub-activities' average (Activity::progressPercent()).
            'activities' => $building->activities()->with('subActivities')->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'work_id', 'description', 'predecessor_id', 'offset_days', 'date_adjustment_days', 'adjustment_reason', 'is_break', 'notes', 'planned_day', 'actual_date'])
                ->map(fn (Activity $activity) => [
                    ...$activity->only(['id', 'work_id', 'description', 'predecessor_id', 'offset_days', 'date_adjustment_days', 'adjustment_reason', 'is_break', 'notes', 'planned_day']),
                    'progress' => $activity->progressPercent(),
                    'sub_activities' => $activity->subActivities->map(fn (SubActivity $sub) => self::subActivity($sub))->all(),
                ]),
            'today' => ScheduleDays::toDate(ScheduleDays::currentDay(null, $project->timezone)),
        ];
    }

    /**
     * A sub-activity as the pages show it.
     *
     * @return array<string, mixed>
     */
    public static function subActivity(SubActivity $sub): array
    {
        return [
            'id' => $sub->id,
            'work_id' => $sub->work_id,
            'position' => $sub->position,
            'name' => $sub->name,
            'planned_date' => $sub->planned_date?->toDateString(),
            'progress' => $sub->progress,
            'done_date' => $sub->done_date?->toDateString(),
            'note' => $sub->note,
        ];
    }
}
