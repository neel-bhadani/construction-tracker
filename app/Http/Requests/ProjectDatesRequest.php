<?php

namespace App\Http\Requests;

use App\Models\Activity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The project's start dates: admin only. Moving the actual start after recorded actuals would
 * leave those actuals before the start, so it is refused with the activities named.
 */
class ProjectDatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('updateDates', $this->route('project'));
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'planned_start_date' => ['required', 'date_format:Y-m-d'],
            'actual_start_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $start = $this->input('actual_start_date') ?: $this->input('planned_start_date');
            $before = Activity::query()
                ->join('scopes', 'scopes.id', '=', 'activities.scope_id')
                ->where('scopes.project_id', $this->route('project')->id)
                ->where('activities.actual_date', '<', $start)
                ->orderBy('scopes.sort_order')->orderBy('activities.sort_order')
                ->get(['scopes.name as scope_name', 'activities.description'])
                ->map(fn ($row) => "{$row->scope_name}: {$row->description}");

            if ($before->isNotEmpty()) {
                $validator->errors()->add(
                    $this->filled('actual_start_date') ? 'actual_start_date' : 'planned_start_date',
                    "These actual dates would fall before the start ({$start}): ".$before->implode(', ').'.',
                );
            }
        }];
    }
}
