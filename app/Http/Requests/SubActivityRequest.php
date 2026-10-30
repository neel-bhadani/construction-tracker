<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesActualDate;
use App\Models\Activity;
use App\Models\SubActivity;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Adding or changing a sub-activity of an activity the account owns. A change may send only some
 * fields: the quick progress buttons send progress and done_date alone. The done date follows the
 * actual date's rules (not in the future, not before the building's actual start), and is required
 * at 100%. A break has no sub-activities.
 */
class SubActivityRequest extends FormRequest
{
    use ValidatesActualDate;

    /** A Response, so the owner's-only refusal stays a 404. */
    public function authorize(): Response
    {
        return $this->sub()
            ? Gate::inspect('update', $this->sub())
            : Gate::inspect('update', $this->activity());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $present = $this->sub() ? 'sometimes' : 'required';

        return [
            'name' => [$present, 'string', 'max:255'],
            'planned_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'progress' => ['sometimes', 'integer', Rule::in(SubActivity::PROGRESS_STEPS)],
            'done_date' => ['sometimes', 'required_if:progress,100', ...$this->actualDateRules($this->activity()->building)],
            'note' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter the sub-activity name.',
            'progress.in' => 'Choose 0, 25, 50, 75 or 100%.',
            'done_date.required_if' => 'Give the date it was done: 100% needs a done date.',
            'done_date.date_format' => 'Enter the done date as a date.',
            'done_date.before_or_equal' => 'The done date cannot be in the future.',
            'done_date.after_or_equal' => 'The done date cannot be before the building\'s actual start ('.$this->actualStart($this->activity()->building).').',
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->activity()->is_break) {
                $validator->errors()->add('activity', 'A break has no sub-activities.');
            }
            // judged as the sub-activity will be: a change may send progress or the date alone.
            // A new one sending neither takes SubActivityEditor::defaults(), which always fit.
            $progress = $this->has('progress') ? (int) $this->input('progress') : $this->sub()?->progress;
            $done = $this->has('done_date') ? $this->input('done_date') : $this->sub()?->done_date;
            if ($progress === 100 && blank($done) && ! $validator->errors()->has('done_date')) {
                $validator->errors()->add('done_date', 'Give the date it was done: 100% needs a done date.');
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        return $this->safe()->only(['name', 'planned_date', 'progress', 'done_date', 'note']);
    }

    public function activity(): Activity
    {
        return $this->route('activity') ?? $this->sub()->activity;
    }

    private function sub(): ?SubActivity
    {
        return $this->route('subActivity');
    }
}
