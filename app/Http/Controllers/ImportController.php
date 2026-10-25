<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportUploadRequest;
use App\Import\ImportUploads;
use App\Import\ScheduleWorkbook;
use Illuminate\Http\JsonResponse;
use PhpOffice\PhpSpreadsheet\Exception as SpreadsheetException;

class ImportController extends Controller
{
    /**
     * The first step of "Import from Excel": keep the file, list its sheets.
     */
    public function store(ImportUploadRequest $request, ImportUploads $uploads): JsonResponse
    {
        $token = $uploads->store($request->file('file'));

        try {
            $sheets = ScheduleWorkbook::sheetNames($uploads->path($token));
        } catch (SpreadsheetException) {
            $uploads->forget($token);

            return response()->json(['message' => 'The file could not be read as an Excel workbook.', 'errors' => ['file' => ['The file could not be read as an Excel workbook.']]], 422);
        }

        return response()->json(['token' => $token, 'name' => $request->file('file')->getClientOriginalName(), 'sheets' => $sheets]);
    }
}
