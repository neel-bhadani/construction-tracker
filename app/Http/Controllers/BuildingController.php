<?php

namespace App\Http\Controllers;

use App\Exceptions\PlanImportException;
use App\Http\Requests\BuildingDatesRequest;
use App\Http\Requests\BuildingRequest;
use App\Import\ImportUploads;
use App\Models\Building;
use App\Models\Project;
use App\Services\BuildingChartService;
use App\Services\ProgressService;
use App\Services\ProjectEditor;
use App\Services\ScheduleDays;
use App\Services\ScheduleEditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A building and its timeline. show() is the timeline screen: every figure is ProgressService
 * output (BuildingMetricsService and LeadProjectionService) passed through as it is.
 */
class BuildingController extends Controller
{
    public function __construct(private readonly ProjectEditor $editor) {}

    public function show(Project $project, Building $building, ProgressService $progress, BuildingChartService $charts): Response
    {
        Gate::authorize('view', $building);

        $buildingProgress = $progress->building($building);

        return Inertia::render('Buildings/Show', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'buildings' => $project->buildings()->orderBy('sort_order')->orderBy('id')->get(['id', 'name']),
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
            'chart' => $charts->chart($building),
            // the stored fields the edit form starts from; derived figures come from the services.
            // planned_day is what the dialog turns a typed planned date into an offset with.
            'activities' => $building->activities()->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'description', 'predecessor_id', 'offset_days', 'date_adjustment_days', 'adjustment_reason', 'is_break', 'notes', 'planned_day']),
            'today' => ScheduleDays::toDate(ScheduleDays::currentDay(null, $project->timezone)),
        ]);
    }

    /** The progress chart's series on their own, for anything that needs them without the page. */
    public function chart(Project $project, Building $building, BuildingChartService $charts): JsonResponse
    {
        Gate::authorize('view', $building);

        return response()->json($charts->chart($building));
    }

    public function store(BuildingRequest $request, Project $project, ImportUploads $uploads): RedirectResponse
    {
        try {
            $building = $this->editor->createBuilding($project, $request->safe()->only(['name', 'type', 'planned_start_date']), $request->user(), $request->plan, $request->origin);
        } catch (PlanImportException $exception) {
            return back()->withErrors(['sheet' => $exception->getMessage()]);
        }

        if ($request->input('plan') === 'import') {
            $uploads->forget($request->string('import_token')->toString());
        }

        return back()->with('success', "Added [{$building->name}].");
    }

    public function update(BuildingRequest $request, Project $project, Building $building): RedirectResponse
    {
        $building = $this->editor->updateBuilding($building, $request->safe()->only(['name', 'type']), $request->user());

        return back()->with('success', "Saved [{$building->name}].");
    }

    public function dates(BuildingDatesRequest $request, Project $project, Building $building, ScheduleEditor $schedule): RedirectResponse
    {
        $schedule->updateBuildingDates($building, $request->validated('planned_start_date'), $request->validated('actual_start_date'), $request->user());

        return back()->with('success', "Start dates saved. [{$building->name}] has been recalculated.");
    }

    public function move(Request $request, Project $project, Building $building): RedirectResponse
    {
        Gate::authorize('update', $building);
        $request->validate(['direction' => ['required', 'in:up,down']]);

        $this->editor->moveBuilding($building, $request->input('direction'), $request->user());

        return back();
    }

    public function destroy(Request $request, Project $project, Building $building): RedirectResponse
    {
        Gate::authorize('delete', $building);

        $this->editor->deleteBuilding($building, $request->user());

        return redirect()->route('projects.show', $project)->with('success', "Deleted [{$building->name}]. Its activities and history are kept.");
    }

    /** A link from before projects existed: the same timeline at its new address. */
    public function legacy(Building $building): RedirectResponse
    {
        Gate::authorize('view', $building);

        return redirect()->route('buildings.show', [$building->project_id, $building], 301);
    }
}
