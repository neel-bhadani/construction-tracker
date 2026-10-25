<?php

namespace App\Http\Controllers;

use App\Exceptions\ProjectHasBuildingsException;
use App\Http\Requests\ProjectRequest;
use App\Models\Building;
use App\Models\Project;
use App\Services\ProjectEditor;
use App\Services\ProjectSummaryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Projects, and the buildings within one. Every figure is ProjectSummaryService output; every
 * write goes through ProjectEditor; every endpoint is authorised by ProjectPolicy.
 */
class ProjectController extends Controller
{
    public function __construct(
        private readonly ProjectEditor $editor,
        private readonly ProjectSummaryService $summaries,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Project::class);

        return Inertia::render('Projects/Index', [
            'projects' => Project::orderBy('name')->get()->map(fn (Project $project) => $this->summaries->summarise($project)),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'defaultTimezone' => config('app.timezone'),
            'can' => [
                'create' => $request->user()->can('create', Project::class),
                'manage' => $request->user()->can('create', Project::class),
            ],
        ]);
    }

    public function show(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        $user = $request->user();

        return Inertia::render('Projects/Show', [
            'project' => $this->summaries->summarise($project),
            'timezones' => \DateTimeZone::listIdentifiers(),
            // a plan can be copied from any building in any project
            'copySources' => Building::with('project:id,name')->withCount('activities')
                ->whereHas('project')->orderBy('project_id')->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (Building $building) => [
                    'id' => $building->id,
                    'label' => "{$building->project->name} › {$building->name}",
                    'activities' => $building->activities_count,
                ]),
            'can' => [
                'manageProject' => $user->can('update', $project),
                'manageBuildings' => $user->can('create', Building::class),
            ],
        ]);
    }

    public function store(ProjectRequest $request): RedirectResponse
    {
        $project = $this->editor->createProject($request->project(), $request->user());

        return redirect()->route('projects.show', $project)->with('success', "Created [{$project->name}]. Add its buildings next.");
    }

    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        $project = $this->editor->updateProject($project, $request->project(), $request->user());

        return back()->with('success', "Saved [{$project->name}].");
    }

    public function destroy(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('delete', $project);

        try {
            $this->editor->deleteProject($project, $request->user());
        } catch (ProjectHasBuildingsException $exception) {
            return back()->withErrors(['project' => $exception->getMessage()]);
        }

        return redirect()->route('projects.index')->with('success', "Deleted [{$project->name}].");
    }
}
