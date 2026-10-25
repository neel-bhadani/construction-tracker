<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesActualDate;
use App\Models\Activity;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Recording, changing or clearing an actual date: the quick form on each row. Clearing is always
 * allowed; a date follows ValidatesActualDate, so a break cannot have one.
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

    private function activity(): Activity
    {
        return $this->route('activity');
    }
}
