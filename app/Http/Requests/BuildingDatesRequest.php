<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * A building's start dates. Moving the actual start after recorded actuals would
 * leave those actuals before the start, so it is refused with the activities named.
 */
class BuildingDatesRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('updateDates', $this->route('building'));
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
            $before = $this->route('building')->activities()
                ->where('actual_date', '<', $start)
                ->orderBy('sort_order')->orderBy('id')
                ->pluck('description');

            if ($before->isNotEmpty()) {
                $validator->errors()->add(
                    $this->filled('actual_start_date') ? 'actual_start_date' : 'planned_start_date',
                    "These actual dates would fall before the start ({$start}): ".$before->implode(', ').'.',
                );
            }
        }];
    }
}
