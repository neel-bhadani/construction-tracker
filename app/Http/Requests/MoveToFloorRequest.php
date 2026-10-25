<?php

namespace App\Http\Requests;

use App\Models\Activity;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * "Move to floor" from a row's menu: a floor of the same building, or none for the whole building.
 * A break stays on no floor.
 */
class MoveToFloorRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('update', $this->activity());
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'floor_id' => [
                'nullable', 'integer',
                Rule::exists('floors', 'id')->where('building_id', $this->activity()->building_id)->whereNull('deleted_at'),
                ...($this->activity()->is_break ? ['prohibited'] : []),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'floor_id.exists' => 'Choose a floor of this building.',
            'floor_id.prohibited' => 'A break is on no floor.',
        ];
    }

    private function activity(): Activity
    {
        return $this->route('activity');
    }
}
