<?php

namespace App\Http\Requests;

use App\Services\ScheduleDays;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Recording, changing or clearing an actual date. It records something that happened, so it cannot be in the future (today in the project's timezone) or before the
 * building's actual start.
 */
class RecordActualRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('recordActual', $this->route('activity'));
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'actual_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$this->today(), 'after_or_equal:'.$this->actualStart()],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'actual_date.before_or_equal' => 'The actual date cannot be in the future.',
            'actual_date.after_or_equal' => 'The actual date cannot be before the building\'s actual start ('.$this->actualStart().').',
        ];
    }

    private function today(): string
    {
        return ScheduleDays::toDate(ScheduleDays::currentDay(null, $this->route('activity')->building->project->timezone));
    }

    private function actualStart(): string
    {
        $building = $this->route('activity')->building;

        return ($building->actual_start_date ?? $building->planned_start_date)->toDateString();
    }
}
