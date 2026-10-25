<?php

namespace App\Http\Controllers;

use App\Http\Requests\FloorRangeRequest;
use App\Http\Requests\FloorRequest;
use App\Models\Building;
use App\Models\Floor;
use App\Models\Project;
use App\Services\FloorEditor;
use App\Services\ProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A building's floors. Every write goes through FloorEditor; every endpoint is authorised by the
 * floor or building policy, and a floor under the wrong building is a 404 (scoped bindings).
 */
class FloorController extends Controller
{
    public function __construct(private readonly FloorEditor $editor) {}

    /** Each floor's progress on its own, as the building page shows it. */
    public function index(Project $project, Building $building, ProgressService $progress): JsonResponse
    {
        Gate::authorize('view', $building);

        return response()->json($progress->building($building)->floors);
    }

    public function store(FloorRequest $request, Project $project, Building $building): RedirectResponse
    {
        $floor = $this->editor->add($building, $request->validated('name'), (int) $request->validated('level'), $request->user());

        return back()->with('success', "Added [{$floor->name}].");
    }

    public function range(FloorRangeRequest $request, Project $project, Building $building): RedirectResponse
    {
        $added = $this->editor->addRange($building, (int) $request->validated('up_to'), (int) $request->validated('basements'), $request->user());

        return back()->with('success', match (count($added)) {
            0 => 'Every one of those floors is already there. Nothing was added.',
            1 => "Added [{$added[0]->name}].",
            default => 'Added '.count($added).' floors.',
        });
    }

    public function update(FloorRequest $request, Project $project, Building $building, Floor $floor): RedirectResponse
    {
        $floor = $this->editor->update($floor, $request->validated('name'), (int) $request->validated('level'), $request->user());

        return back()->with('success', "Saved [{$floor->name}].");
    }

    public function destroy(Request $request, Project $project, Building $building, Floor $floor): RedirectResponse
    {
        Gate::authorize('delete', $floor);

        $moved = $this->editor->delete($floor, $request->user());

        return back()->with('success', $moved === 0
            ? "Deleted [{$floor->name}]."
            : "Deleted [{$floor->name}]. Its {$moved} ".($moved === 1 ? 'activity is' : 'activities are').' now Whole building.');
    }
}
