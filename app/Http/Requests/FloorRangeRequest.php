<?php

namespace App\Http\Requests;

use App\Models\Floor;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The quick option, "Ground + 1 to 13": the ground floor, floors 1 to up_to, and any basements.
 */
class FloorRangeRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('create', [Floor::class, $this->route('building')]);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'up_to' => ['required', 'integer', 'min:0', 'max:200'],
            'basements' => ['nullable', 'integer', 'min:0', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'up_to.required' => 'Give the top floor: 13 adds the ground floor and the 1st to the 13th.',
            'up_to.max' => 'A building can have at most 200 floors here.',
            'basements.max' => 'A building can have at most 20 basements here.',
        ];
    }
}
