<?php

namespace App\Services;

use App\Data\PlanImportResult;
use App\Exceptions\PlanImportException;
use App\Import\PlanDocument;
use App\Import\PlanDocumentValidator;
use App\Import\ScheduleWorkbook;
use App\Models\Activity;
use App\Models\Building;
use App\Models\PlanImport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes an import document (PlanDocument) as one new project of $owner, all in one transaction.
 *
 * After writing, inside the same transaction, it reads back what it wrote: every planned date must
 * equal the JSON's (Excel's) and every building's figures must equal `expected`. Any difference
 * rolls the whole import back. A dry run does all of it, real inserts against real constraints,
 * then rolls back anyway, so what it reports is what a real run would do.
 */
class PlanImporter
{
    private const FLOAT_TOLERANCE = 1e-9;

    public function __construct(
        private readonly PlanDocumentValidator $validator,
        private readonly ProjectEditor $projects,
        private readonly PlanWriter $writer,
        private readonly BuildingMetricsService $metrics,
    ) {}

    /**
     * @param  array<string, mixed>  $document
     * @param  string  $file  for the log: the file's name
     *
     * @throws PlanImportException naming every problem; nothing is written
     */
    public function import(array $document, User $owner, string $file, string $sha256, ?string $as = null, bool $dryRun = false): PlanImportResult
    {
        $problems = $this->validator->validate($document);
        if ($problems !== []) {
            throw new PlanImportException("The file is not a plan that can be imported:\n  ".implode("\n  ", $problems));
        }

        $name = trim($as ?? $document['project']['name']);
        if ($name === '' || mb_strlen($name) > 150) {
            throw new PlanImportException('The project name must be 1 to 150 characters.');
        }

        DB::beginTransaction();

        try {
            $result = $this->write($document, $owner, $name, $file, $sha256, $dryRun);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        return $result;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function write(array $document, User $owner, string $name, string $file, string $sha256, bool $dryRun): PlanImportResult
    {
        if ($owner->projects()->where('name', $name)->lockForUpdate()->exists()) {
            throw new PlanImportException("[{$owner->email}] already has a project named [{$name}]. Import it under another name with --as=\"{$name} (2)\".");
        }

        $earlier = PlanImport::where('owner_id', $owner->id)->where('sha256', $sha256)->first();
        if ($earlier !== null) {
            throw new PlanImportException("This file was already imported for [{$owner->email}] on {$earlier->created_at->format('j M Y H:i')}, as [{$earlier->project_name}]. Nothing was written.");
        }

        $project = $this->projects->createProject([
            'name' => $name,
            'details' => null,
            'planned_start_date' => min(array_column($document['buildings'], 'planned_start')),
            'timezone' => $document['project']['timezone'],
        ], $owner);

        $buildings = [];
        $mismatches = [];
        $datesChecked = $figuresChecked = 0;

        foreach ($document['buildings'] as $data) {
            $building = $this->projects->createBuilding($project, [
                'name' => $data['name'], 'type' => $data['type'], 'planned_start_date' => $data['planned_start'],
            ], $owner, origin: "imported from {$file}");
            if ($data['actual_start'] !== null) {
                $building->forceFill(['actual_start_date' => $data['actual_start']])->save();
            }

            $this->writer->write($building, $this->rows($data['activities']), $owner, "imported from {$file}");

            // read back from the database: Excel's planned dates, then the Chart sheet's figures
            $stored = Activity::where('building_id', $building->id)->pluck('planned_date', 'sort_order');
            foreach ($data['activities'] as $activity) {
                $datesChecked++;
                $date = $stored[$activity['position']]?->toDateString();
                if ($date !== $activity['planned_date']) {
                    $mismatches[] = "{$data['name']} row {$activity['row']} [{$activity['name']}]: planned {$date}, Excel {$activity['planned_date']}";
                }
            }

            $actual = PlanDocument::expected($this->metrics->forBuilding($building->fresh()));
            foreach ($document['expected'][$data['name']] as $figure => $expected) {
                if (! array_key_exists($figure, $actual)) {
                    continue;
                }
                $figuresChecked++;
                if (! $this->same($expected, $actual[$figure])) {
                    $mismatches[] = "{$data['name']} {$figure}: imported ".json_encode($actual[$figure]).', expected '.json_encode($expected);
                }
            }

            $buildings[] = [
                'name' => $data['name'],
                'type' => $data['type'],
                'activities' => count($data['activities']),
                'actuals' => count(array_filter(array_column($data['activities'], 'actual_date'))),
                'breaks' => count(array_filter(array_column($data['activities'], 'is_break'))),
            ];
        }

        if ($mismatches !== []) {
            throw new PlanImportException("The imported plan does not match the file. Nothing was written:\n  ".implode("\n  ", $mismatches));
        }

        PlanImport::create([
            'owner_id' => $owner->id,
            'project_id' => $project->id,
            'project_name' => $name,
            'file' => $file,
            'sha256' => $sha256,
            'counts' => ['buildings' => $buildings, 'dates_checked' => $datesChecked, 'figures_checked' => $figuresChecked],
        ]);

        return new PlanImportResult($dryRun, $dryRun ? null : $project->id, $name, $buildings, $datesChecked, $figuresChecked);
    }

    /**
     * The activities in the shape PlanWriter writes, keyed by row so "comes after" can find them.
     *
     * @param  list<array<string, mixed>>  $activities
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $activities): array
    {
        $rows = [];
        foreach ($activities as $activity) {
            $rows[$activity['row']] = [
                'description' => $activity['name'],
                'predecessor' => $activity['comes_after_row'],
                'offset_days' => $activity['offset_days'],
                'date_adjustment_days' => $activity['adjustment'],
                'adjustment_reason' => $activity['adjustment'] === 0 ? null : ScheduleWorkbook::ADJUSTMENT_REASON,
                'is_break' => $activity['is_break'],
                'sort_order' => $activity['position'],
                'actual_date' => $activity['actual_date'],
                'notes' => $activity['note'] ?? null,
            ];
        }

        return $rows;
    }

    private function same(mixed $expected, mixed $actual): bool
    {
        if (is_float($expected) || is_float($actual)) {
            return $expected !== null && $actual !== null && abs((float) $expected - (float) $actual) <= self::FLOAT_TOLERANCE;
        }

        return $expected === $actual;
    }
}
