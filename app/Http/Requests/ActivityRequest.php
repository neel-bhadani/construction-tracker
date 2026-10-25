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
use Illuminate\Validation\Rule;

/**
 * Adding or editing an activity in a building the account owns: its name, where it comes in the
 * chain, its floor, its planned date (stored as the offset), its actual date, the break flag and
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
            'is_break' => ['required', 'boolean'],
            'actual_date' => [...$this->actualDateRules($this->building()), 'prohibited_if_accepted:is_break'],
            // none is the whole building; a break is on no floor
            'floor_id' => ['nullable', 'integer', 'prohibited_if_accepted:is_break',
                Rule::exists('floors', 'id')->where('building_id', $this->building()->id)->whereNull('deleted_at')],
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
            'floor_id.exists' => 'Choose a floor of this building.',
            'floor_id.prohibited_if_accepted' => 'A break is on no floor.',
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
            'actual_date' => $this->validated('actual_date'),
            'floor_id' => $this->filled('floor_id') ? $this->integer('floor_id') : null,
        ];
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
