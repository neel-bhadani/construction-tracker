<?php

namespace App\Http\Requests;

use App\Models\Building;
use App\Models\Floor;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Adding one floor, or renaming one: a name unique in the building and a level to sort by.
 */
class FloorRequest extends FormRequest
{
    public function authorize(): Response
    {
        return $this->floor() ? Gate::inspect('update', $this->floor()) : Gate::inspect('create', [Floor::class, $this->building()]);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50',
                Rule::unique('floors', 'name')->where('building_id', $this->building()->id)->whereNull('deleted_at')->ignore($this->floor())],
            'level' => ['required', 'integer', 'min:-20', 'max:300'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the floor a name.',
            'name.unique' => 'This building already has a floor with that name.',
            'level.required' => 'Give the floor\'s level: 0 for ground, 1 for the 1st floor, -1 for a basement.',
            'level.integer' => 'The level is a whole number: 0 for ground, 1 for the 1st floor, -1 for a basement.',
        ];
    }

    private function building(): Building
    {
        return $this->route('building');
    }

    private function floor(): ?Floor
    {
        return $this->route('floor');
    }
}
