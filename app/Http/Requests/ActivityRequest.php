<?php

namespace App\Http\Requests;

use App\Data\SchedulePreview;
use App\Http\Requests\Concerns\ValidatesChain;
use App\Models\Activity;
use App\Models\Building;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Adding or editing an activity in a building the account owns.
 */
class ActivityRequest extends FormRequest
{
    use ValidatesChain;

    public ?SchedulePreview $preview = null;

    /** A Response, so the owner's-only refusal stays a 404. */
    public function authorize(): Response
    {
        return $this->activity()
            ? Gate::inspect('update', $this->activity())
            : Gate::inspect('create', [Activity::class, $this->building()]);
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
