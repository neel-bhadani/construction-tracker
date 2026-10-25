<?php

namespace App\Http\Requests;

use App\Services\ScheduleDays;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Recording, changing or clearing an actual date: editor and admin. It records something that
 * happened, so it cannot be in the future (today is the Kolkata date) or before the project's
 * actual start.
 */
class RecordActualRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('recordActual', $this->route('activity'));
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
            'actual_date.after_or_equal' => 'The actual date cannot be before the project\'s actual start ('.$this->actualStart().').',
        ];
    }

    private function today(): string
    {
        return ScheduleDays::toDate(ScheduleDays::currentDay());
    }

    private function actualStart(): string
    {
        $project = $this->route('activity')->scope->project;

        return ($project->actual_start_date ?? $project->planned_start_date)->toDateString();
    }
}
