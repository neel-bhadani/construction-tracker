<?php

namespace App\Http\Requests;

use App\Data\SchedulePreview;
use App\Http\Requests\Concerns\ValidatesActualDate;
use App\Http\Requests\Concerns\ValidatesChain;
use App\Models\Activity;
use App\Models\Building;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Adding or editing an activity in a building the account owns: its name, where it comes in the
 * chain, its planned date (stored as the offset), its actual date, the break flag and
 * a note.
 */
class ActivityRequest extends FormRequest
{
    use ValidatesActualDate, ValidatesChain;

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
            // with sub-activities the actual date is theirs, and a break has none
            'is_break' => ['required', 'boolean', ...($this->hasSubActivities() ? ['declined'] : [])],
            'actual_date' => $this->hasSubActivities()
                ? ['prohibited']
                : [...$this->actualDateRules($this->building()), 'prohibited_if_accepted:is_break'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'description.required' => 'Enter the activity name.',
            ...$this->chainMessages(),
            ...$this->actualDateMessages($this->building()),
            'is_break.declined' => 'An activity with sub-activities cannot be a break. Delete its sub-activities first.',
            ...($this->hasSubActivities() ? ['actual_date.prohibited' => 'This activity is done through its sub-activities.'] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function plan(): array
    {
        return [
            ...$this->safe()->only(['description', 'notes']),
            ...$this->chain(),
            'is_break' => $this->boolean('is_break'),
            ...($this->hasSubActivities() ? [] : ['actual_date' => $this->validated('actual_date')]),
        ];
    }

    protected function building(): Building
    {
        return $this->route('building') ?? $this->activity()->building;
    }

    private function hasSubActivities(): bool
    {
        return $this->activity()?->subActivities()->exists() ?? false;
    }

    protected function activity(): ?Activity
    {
        return $this->route('activity');
    }
}
