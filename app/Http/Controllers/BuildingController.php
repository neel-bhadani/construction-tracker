<?php

namespace App\Http\Controllers;

use App\Enums\BuildingType;
use App\Exceptions\PlanImportException;
use App\Http\Requests\BuildingDatesRequest;
use App\Http\Requests\BuildingRequest;
use App\Import\ImportUploads;
use App\Models\Building;
use App\Models\Project;
use App\Services\BuildingChartService;
use App\Services\ProjectEditor;
use App\Services\ScheduleEditor;
use App\Services\TimelinePage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A building and its timeline. show() is the timeline screen (TimelinePage); the project's Common
 * activities are shown on the project page instead.
 */
class BuildingController extends Controller
{
    public function __construct(private readonly ProjectEditor $editor) {}

    public function show(Project $project, Building $building, TimelinePage $timeline): Response|RedirectResponse
    {
        Gate::authorize('view', $building);

        // the common activities live on the project page
        if ($building->isCommon()) {
            return redirect()->route('projects.common', $project);
        }

        return Inertia::render('Buildings/Show', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'buildings' => $project->buildings()->where('type', BuildingType::Building)->orderBy('sort_order')->orderBy('id')->get(['id', 'name']),
            ...$timeline->props($project, $building),
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
            $building = $this->editor->createBuilding($project, $request->safe()->only(['name', 'planned_start_date']), $request->user(), $request->plan, $request->origin);
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
        $building = $this->editor->updateBuilding($building, $request->safe()->only(['name']), $request->user());

        return back()->with('success', "Saved [{$building->name}].");
    }

    public function dates(BuildingDatesRequest $request, Project $project, Building $building, ScheduleEditor $schedule): RedirectResponse
    {
        $schedule->updateBuildingDates($building, $request->validated('planned_start_date'), $request->validated('actual_start_date'), $request->user());

        return back()->with('success', "Start dates saved. [{$building->name}] has been recalculated.");
    }

    public function move(Request $request, Project $project, Building $building): RedirectResponse
    {
        Gate::authorize('move', $building);
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
