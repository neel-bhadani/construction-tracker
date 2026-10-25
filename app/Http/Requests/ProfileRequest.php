<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The signed-in account's own name, email and phone.
 */
class ProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->user())],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]*$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Another account already uses that email.',
            'phone.regex' => 'A phone number can only have digits, spaces, +, - and brackets.',
        ];
    }

    /**
     * @return array{name: string, email: string, phone: ?string}
     */
    public function details(): array
    {
        $data = $this->validated();
        $data['phone'] = trim((string) ($data['phone'] ?? '')) === '' ? null : trim($data['phone']);

        return $data;
    }
}
