<?php

namespace App\Services;

use App\Data\ActivityMetrics;
use App\Data\ActivityProjection;
use App\Models\Activity;
use App\Models\Building;
use App\Models\Project;
use App\Models\SubActivity;
use DateTimeInterface;

/**
 * The project page's All activities tab: one group per timeline, the Common activities first and
 * then the buildings in their order, each with its own figures and every row's.
 *
 * Every figure is the services' own (BuildingMetricsService and LeadProjectionService, as on a
 * building's page); nothing is computed here but the merge. The activities and their
 * sub-activities are read in two queries for the whole project, however many rows there are.
 */
class AllActivitiesPage
{
    public function __construct(
        private readonly BuildingMetricsService $metrics,
        private readonly LeadProjectionService $leads,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function groups(Project $project, ?DateTimeInterface $today = null): array
    {
        $buildings = $project->buildings()->orderByRaw("type != 'common_work'")->orderBy('sort_order')->orderBy('id')->get();
        $activities = Activity::whereIn('building_id', $buildings->modelKeys())->with('subActivities')
            ->orderBy('sort_order')->orderBy('id')->get()->groupBy('building_id');

        return $buildings->map(function (Building $building) use ($activities, $project, $today) {
            $rows = $activities->get($building->id, collect());
            $metrics = $this->metrics->forActivities($building, $rows, $project->timezone, $today);
            $projection = collect($this->leads->project($metrics)->activities)->keyBy('id');
            $figures = collect($metrics->activities)->keyBy('id');

            return [
                'id' => $building->id,
                'name' => $building->name,
                'type' => $building->type->value,
                'planned_start_date' => $building->planned_start_date->toDateString(),
                'actual_start_date' => $building->actual_start_date?->toDateString(),
                // the building's actual start: no actual or done date before it
                'earliest' => $metrics->actualStartDate,
                'progress' => $metrics->progress,
                'daysCompleted' => $metrics->daysCompleted,
                'totalPlannedDays' => $metrics->totalPlannedDays,
                'currentLead' => $metrics->currentLead,
                'plannedFinish' => $metrics->plannedFinish,
                'forecastFinish' => $metrics->forecastHoldLead ?? $metrics->plannedFinish,
                'rows' => $rows->map(fn (Activity $activity) => $this->row($activity, $figures[$activity->id], $projection[$activity->id]))->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * A row with what the list shows and what the Edit dialog starts from.
     *
     * @return array<string, mixed>
     */
    private function row(Activity $activity, ActivityMetrics $figures, ActivityProjection $projection): array
    {
        return [
            ...$activity->only(['id', 'work_id', 'description', 'predecessor_id', 'offset_days', 'date_adjustment_days', 'adjustment_reason', 'is_break', 'notes', 'planned_day']),
            'plannedDate' => $figures->plannedDate,
            'actualDate' => $figures->actualDate,
            'aheadDays' => $figures->aheadDays,
            'status' => $projection->status,
            'actualOrForecast' => $projection->actualOrForecast,
            'progress' => $activity->progressPercent(),
            'sub_activities' => $activity->subActivities->map(fn (SubActivity $sub) => TimelinePage::subActivity($sub))->all(),
        ];
    }
}
