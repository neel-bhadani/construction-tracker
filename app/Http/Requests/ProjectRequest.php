<?php

namespace App\Http\Requests;

use App\Models\Project;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProjectRequest extends FormRequest
{
    public function authorize(): Response
    {
        $project = $this->route('project');

        return $project ? Gate::inspect('update', $project) : Gate::inspect('create', Project::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150',
                Rule::unique('projects', 'name')->where('owner_id', $this->user()->id)->whereNull('deleted_at')->ignore($this->route('project'))],
            // none: a new project takes its initials, an edited one keeps its code
            'code' => ['nullable', 'string', 'max:8', 'regex:/^[A-Z0-9]+$/',
                Rule::unique('projects', 'code')->where('owner_id', $this->user()->id)->whereNull('deleted_at')->ignore($this->route('project'))],
            'details' => ['nullable', 'string', 'max:5000'],
            'planned_start_date' => ['required', 'date_format:Y-m-d'],
            'timezone' => ['required', 'timezone:all'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'You already have a project with that name.',
            'code.max' => 'The code is at most 8 letters and digits.',
            'code.regex' => 'The code is letters A–Z and digits only.',
            'code.unique' => 'Another of your projects uses this code.',
            'planned_start_date.required' => 'Give the planned start date. New buildings start on it unless you choose another.',
            'timezone.timezone' => 'Choose a timezone from the list.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'timezone' => $this->input('timezone') ?: config('app.timezone'),
            'code' => strtoupper(trim((string) $this->input('code'))) ?: null,
        ]);
    }

    /**
     * @return array{name: string, code?: ?string, details: ?string, planned_start_date: string, timezone: string}
     */
    public function project(): array
    {
        $data = $this->validated();
        $data['details'] = trim((string) ($data['details'] ?? '')) === '' ? null : $data['details'];
        if (($data['code'] ?? null) === null) {
            unset($data['code']);
        }

        return $data;
    }
}
