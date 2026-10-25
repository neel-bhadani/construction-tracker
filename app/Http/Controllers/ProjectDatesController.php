<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProjectDatesRequest;
use App\Models\Project;
use App\Services\ScheduleEditor;
use Illuminate\Http\RedirectResponse;

class ProjectDatesController extends Controller
{
    /** Authorised by ProjectPolicy::updateDates in the form request; recalculates every scope. */
    public function update(ProjectDatesRequest $request, Project $project, ScheduleEditor $editor): RedirectResponse
    {
        $editor->updateProjectDates(
            $project,
            $request->validated('planned_start_date'),
            $request->validated('actual_start_date'),
            $request->user(),
        );

        return back()->with('success', 'Project dates saved. Every scope has been recalculated.');
    }
}
