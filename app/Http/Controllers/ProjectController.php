<?php

namespace App\Http\Controllers;

use App\Exceptions\ProjectHasBuildingsException;
use App\Http\Requests\ProjectRequest;
use App\Models\Building;
use App\Models\Project;
use App\Services\AllActivitiesPage;
use App\Services\ProjectEditor;
use App\Services\ProjectSummaryService;
use App\Services\ScheduleDays;
use App\Services\TimelinePage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Projects, and the buildings and Common activities within one. Every figure is
 * ProjectSummaryService output; every write goes through ProjectEditor; every endpoint is
 * authorised by ProjectPolicy, which shows a project to its owner only.
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
            'projects' => $request->user()->projects()->orderBy('name')->get()->map(fn (Project $project) => $this->summaries->summarise($project)),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'defaultTimezone' => config('app.timezone'),
        ]);
    }

    /** The project page, Buildings tab: overall progress and a card per building. */
    public function show(Request $request, Project $project): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('Projects/Show', [...$this->page($request, $project), 'tab' => 'buildings']);
    }

    /** The project page, Common activities tab: the project's own timeline, as a building's page shows one. */
    public function common(Request $request, Project $project, TimelinePage $timeline): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('Projects/Show', [
            ...$this->page($request, $project),
            'tab' => 'common',
            'timeline' => $timeline->props($project, $project->commonActivities()->firstOrFail()),
        ]);
    }

    /** The project page, All activities tab: every activity of the project, grouped by timeline. */
    public function activities(Request $request, Project $project, AllActivitiesPage $page): Response
    {
        Gate::authorize('view', $project);

        return Inertia::render('Projects/Show', [
            ...$this->page($request, $project),
            'tab' => 'all',
            'groups' => $page->groups($project),
            'today' => ScheduleDays::toDate(ScheduleDays::currentDay(null, $project->timezone)),
        ]);
    }

    /**
     * What every tab of the project page shows.
     *
     * @return array<string, mixed>
     */
    private function page(Request $request, Project $project): array
    {
        return [
            'project' => $this->summaries->summarise($project),
            'timezones' => \DateTimeZone::listIdentifiers(),
            // a plan can be copied from any building in any of this account's projects
            'copySources' => Building::with('project:id,name')->withCount('activities')
                ->whereHas('project', fn ($query) => $query->whereBelongsTo($request->user(), 'owner'))
                ->orderBy('project_id')->orderByRaw("type != 'common_work'")->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (Building $building) => [
                    'id' => $building->id,
                    'label' => "{$building->project->name} › {$building->name}",
                    'activities' => $building->activities_count,
                ]),
        ];
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
