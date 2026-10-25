<?php

namespace App\Http\Requests;

use App\Data\SchedulePreview;
use App\Http\Requests\Concerns\ValidatesChain;
use App\Models\Activity;
use App\Models\Building;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding or editing an activity: admin only, because every field here can reshape the plan.
 */
class ActivityRequest extends FormRequest
{
    use ValidatesChain;

    public ?SchedulePreview $preview = null;

    public function authorize(): bool
    {
        return $this->activity()
            ? $this->user()->can('update', $this->activity())
            : $this->user()->can('create', Activity::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:255'],
            ...$this->chainRules(),
            'adjustment_reason' => ['nullable', 'string', 'max:255', 'required_unless:date_adjustment_days,0'],
            'is_break' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->chainMessages(),
            'adjustment_reason.required_unless' => 'Give a reason for the date adjustment. Hidden nudges are what this replaces.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function plan(): array
    {
        return [...$this->safe()->only(['description', 'adjustment_reason', 'notes']), ...$this->chain(), 'is_break' => $this->boolean('is_break')];
    }

    protected function building(): Building
    {
        return $this->route('building') ?? $this->activity()->building;
    }

    protected function activity(): ?Activity
    {
        return $this->route('activity');
    }
}
