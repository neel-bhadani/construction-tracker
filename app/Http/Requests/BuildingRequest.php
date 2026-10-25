<?php

namespace App\Http\Requests;

use App\Enums\BuildingType;
use App\Exceptions\PlanImportException;
use App\Import\ImportUploads;
use App\Import\ScheduleWorkbook;
use App\Models\Building;
use App\Models\Project;
use App\Services\ProjectEditor;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Adding a building, with its plan empty, copied from another building in any project, or
 * imported from an uploaded workbook. Editing uses the same rules for name and type only.
 */
class BuildingRequest extends FormRequest
{
    public Building|ScheduleWorkbook|null $plan = null;

    public ?string $origin = null;

    public function authorize(): Response
    {
        $building = $this->route('building');

        return $building ? Gate::inspect('update', $building) : Gate::inspect('create', Building::class);
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:100',
                Rule::unique('buildings', 'name')->where('project_id', $this->project()->id)->whereNull('deleted_at')->ignore($this->route('building'))],
            'type' => ['required', Rule::enum(BuildingType::class)],
        ];

        if ($this->route('building')) {
            return $rules;
        }

        return [
            ...$rules,
            'planned_start_date' => ['nullable', 'date_format:Y-m-d'],
            'plan' => ['required', Rule::in(['empty', 'copy', 'import'])],
            'copy_from' => ['required_if:plan,copy', 'nullable', 'integer', Rule::exists('buildings', 'id')->whereNull('deleted_at')],
            'import_token' => ['required_if:plan,import', 'nullable', 'string'],
            'sheet' => ['required_if:plan,import', 'nullable', 'string', 'max:31'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'This project already has a building with that name.',
            'type.enum' => 'Choose Building or Common work.',
            'copy_from.required_if' => 'Choose the building to copy the plan from.',
            'copy_from.exists' => 'That building no longer exists. Choose another.',
            'import_token.required_if' => 'Choose an Excel file to import.',
            'sheet.required_if' => 'Choose the sheet that holds the plan.',
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($this->route('building') || $validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->input('plan') === 'copy') {
                $source = Building::with('project')->findOrFail($this->integer('copy_from'));
                $this->plan = $source;
                $this->origin = "copied from {$source->project->name} › {$source->name}";
            }

            if ($this->input('plan') === 'import') {
                $path = app(ImportUploads::class)->path($this->string('import_token')->toString());

                if ($path === null) {
                    $validator->errors()->add('import_token', 'The uploaded file has expired. Choose it again.');

                    return;
                }

                try {
                    $this->plan = ProjectEditor::readWorkbook($path, $this->string('sheet')->toString());
                    $this->origin = 'imported from Excel › '.$this->string('sheet');
                } catch (PlanImportException $exception) {
                    $validator->errors()->add('sheet', $exception->getMessage());
                }
            }
        }];
    }

    private function project(): Project
    {
        return $this->route('project');
    }
}
