<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesActualDate;
use App\Models\Activity;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

/**
 * Recording, changing or clearing an actual date: the quick form on each row. Clearing is always
 * allowed; a date follows ValidatesActualDate, so a break cannot have one. An activity with
 * sub-activities is done through them, never by hand.
 */
class RecordActualRequest extends FormRequest
{
    use ValidatesActualDate;

    public function authorize(): Response
    {
        return Gate::inspect('recordActual', $this->activity());
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'actual_date' => [...$this->actualDateRules($this->activity()->building), ...($this->activity()->is_break ? ['prohibited'] : [])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->actualDateMessages($this->activity()->building);
    }

    /**
     * Not even clearing it: the sub-activities set and clear it.
     *
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->activity()->subActivities()->exists()) {
                $validator->errors()->add('actual_date', 'This activity is done through its sub-activities.');
            }
        }];
    }

    private function activity(): Activity
    {
        return $this->route('activity');
    }
}
