<?php

namespace App\Http\Requests;

use App\Models\Building;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * The first step of "Import from Excel": the file, before its sheet is chosen.
 */
class ImportUploadRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('create', Building::class);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:2048', 'mimes:xlsx', 'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/zip,application/octet-stream'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose an Excel file.',
            'file.mimes' => 'Choose an Excel workbook (.xlsx).',
            'file.mimetypes' => 'Choose an Excel workbook (.xlsx).',
            'file.max' => 'The file is larger than 2 MB.',
        ];
    }
}
