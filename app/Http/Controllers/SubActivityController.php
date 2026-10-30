<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubActivityRequest;
use App\Models\Activity;
use App\Models\SubActivity;
use App\Services\SubActivityEditor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sub-activities of an activity. Every endpoint is authorised by its policy (the activity's owner
 * only, a 404 to anyone else) and every write goes through SubActivityEditor.
 */
class SubActivityController extends Controller
{
    public function __construct(private readonly SubActivityEditor $editor) {}

    public function store(SubActivityRequest $request, Activity $activity): RedirectResponse
    {
        $sub = $this->editor->create($activity, $request->fields(), $request->user());

        return back()->with('success', "Added [{$sub->work_id} {$sub->name}].");
    }

    public function update(SubActivityRequest $request, SubActivity $subActivity): RedirectResponse
    {
        $sub = $this->editor->update($subActivity, $request->fields(), $request->user());

        return back()->with('success', "Saved [{$sub->work_id} {$sub->name}], {$sub->progress}%.");
    }

    public function destroy(Request $request, SubActivity $subActivity): RedirectResponse
    {
        Gate::authorize('delete', $subActivity);

        $this->editor->delete($subActivity, $request->user());

        return back()->with('success', "Deleted [{$subActivity->work_id} {$subActivity->name}].");
    }

    /** The order they are shown in: every live sub-activity's id, once, top first. */
    public function order(Request $request, Activity $activity): RedirectResponse
    {
        Gate::authorize('update', $activity);
        $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'distinct', Rule::exists('sub_activities', 'id')->where('activity_id', $activity->id)->whereNull('deleted_at')],
        ]);
        if (count($request->input('ids')) !== $activity->subActivities()->count()) {
            throw ValidationException::withMessages(['ids' => 'The new order must list every sub-activity once.']);
        }

        $this->editor->reorder($activity, $request->input('ids'));

        return back();
    }
}
