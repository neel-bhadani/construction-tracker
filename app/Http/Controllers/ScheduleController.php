<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Scope;
use App\Models\User;
use App\Services\LeadProjectionService;
use App\Services\ScheduleDays;
use App\Services\ScopeMetricsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The main page, one scope at a time. Every figure is ScopeMetricsService and
 * LeadProjectionService output passed through as it is; nothing is calculated here.
 */
class ScheduleController extends Controller
{
    /**
     * The first scope, or an empty state: on a first deploy before the import has run, a 404
     * would read as broken.
     */
    public function index(Request $request): RedirectResponse|Response
    {
        Gate::authorize('viewAny', Activity::class);

        $scope = Scope::query()->orderBy('project_id')->orderBy('sort_order')->orderBy('id')->first();

        if ($scope === null) {
            return Inertia::render('Empty', [
                'can' => ['approveUsers' => $request->user()->can('viewPending', User::class)],
            ]);
        }

        return redirect()->route('scopes.show', $scope);
    }

    public function show(Request $request, Scope $scope, ScopeMetricsService $metrics, LeadProjectionService $leads): Response
    {
        Gate::authorize('viewAny', Activity::class);

        $project = $scope->project;
        $scopeMetrics = $metrics->forScope($scope);
        $user = $request->user();

        return Inertia::render('Schedule', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'planned_start_date' => $project->planned_start_date->toDateString(),
                'actual_start_date' => $project->actual_start_date?->toDateString(),
            ],
            'scopes' => $project->scopes()->orderBy('sort_order')->orderBy('id')->get(['id', 'name']),
            'scope' => ['id' => $scope->id, 'name' => $scope->name],
            'metrics' => $scopeMetrics,
            'projection' => $leads->project($scopeMetrics),
            // the stored fields the edit form starts from; derived figures come from the services
            'activities' => $scope->activities()->orderBy('sort_order')->orderBy('id')
                ->get(['id', 'description', 'predecessor_id', 'offset_days', 'date_adjustment_days', 'adjustment_reason', 'is_break', 'notes']),
            'today' => ScheduleDays::toDate(ScheduleDays::currentDay()),
            // what to show; the policies on each endpoint are what actually decide
            'can' => [
                'recordActual' => $user->can('recordActual', new Activity),
                'manageActivities' => $user->can('create', Activity::class),
                'updateProjectDates' => $user->can('updateDates', $project),
                'approveUsers' => $user->can('viewPending', User::class),
            ],
        ]);
    }
}
