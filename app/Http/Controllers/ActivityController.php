<?php

namespace App\Http\Controllers;

use App\Exceptions\ActivityHasDependantsException;
use App\Http\Requests\ActivityPreviewRequest;
use App\Http\Requests\ActivityRequest;
use App\Http\Requests\MoveToFloorRequest;
use App\Http\Requests\RecordActualRequest;
use App\Models\Activity;
use App\Models\Building;
use App\Services\ScheduleEditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Every endpoint is authorised by ActivityPolicy — in the form request, before validation, or
 * here — and every write goes through ScheduleEditor.
 */
class ActivityController extends Controller
{
    public function __construct(private readonly ScheduleEditor $editor) {}

    public function store(ActivityRequest $request, Building $building): RedirectResponse
    {
        $activity = $this->editor->create($building, $request->plan(), $request->user(), ...$request->insertion());

        return back()->with('success', "Added [{$activity->description}].");
    }

    public function update(ActivityRequest $request, Activity $activity): RedirectResponse
    {
        $activity = $this->editor->update($activity, $request->plan(), $request->user());

        return back()->with('success', "Saved [{$activity->description}].");
    }

    public function recordActual(RecordActualRequest $request, Activity $activity): RedirectResponse
    {
        $activity = $this->editor->recordActual(
            $activity,
            $request->validated('actual_date'),
            $request->validated('notes'),
            $request->user(),
        );

        return back()->with('success', $activity->actual_date
            ? "Recorded [{$activity->description}] on {$activity->actual_date->format('j M Y')}."
            : "Cleared the actual date of [{$activity->description}].");
    }

    public function moveToFloor(MoveToFloorRequest $request, Activity $activity): RedirectResponse
    {
        $activity = $this->editor->moveToFloor($activity, $request->filled('floor_id') ? $request->integer('floor_id') : null, $request->user());

        return back()->with('success', "Moved [{$activity->description}] to ".($activity->floor?->name ?? 'Whole building').'.');
    }

    public function destroy(Request $request, Activity $activity): RedirectResponse
    {
        Gate::authorize('delete', $activity);

        try {
            $this->editor->delete($activity, $request->user());
        } catch (ActivityHasDependantsException $exception) {
            return back()->withErrors(['activity' => $exception->getMessage()]);
        }

        return back()->with('success', "Deleted [{$activity->description}].");
    }

    /** What a new activity would move, computed without writing. */
    public function previewNew(ActivityPreviewRequest $request, Building $building): JsonResponse
    {
        return response()->json($request->preview);
    }

    /** What an edit would move, computed without writing. */
    public function previewEdit(ActivityPreviewRequest $request, Activity $activity): JsonResponse
    {
        return response()->json($request->preview);
    }
}
