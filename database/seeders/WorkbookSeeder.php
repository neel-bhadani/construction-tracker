<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\Scope;
use App\Services\ScheduleRecalculator;
use Database\Seeders\Workbook\ScheduleWorkbook;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-off import of the three scopes from docs/workbooks. The chain is parsed from the formulas,
 * then recalculated and compared row by row with the workbook's own cached dates. A single
 * mismatch rolls the whole import back.
 */
class WorkbookSeeder extends Seeder
{
    public const PROJECT_NAME = 'Shaligram Vrundavan';

    public function run(ScheduleRecalculator $recalculator): void
    {
        $workbooks = array_map(ScheduleWorkbook::read(...), array_keys(ScheduleWorkbook::SOURCES));

        $startDates = array_unique(array_column($workbooks, 'startDate'));
        if (count($startDates) !== 1) {
            throw new RuntimeException('Workbooks disagree on the start date: '.implode(', ', $startDates));
        }

        DB::transaction(function () use ($workbooks, $startDates, $recalculator) {
            if (Project::where('name', self::PROJECT_NAME)->exists()) {
                throw new RuntimeException('['.self::PROJECT_NAME.'] has already been imported.');
            }

            $project = Project::create(['name' => self::PROJECT_NAME, 'planned_start_date' => $startDates[0]]);
            $mismatches = [];

            foreach ($workbooks as $index => $workbook) {
                $scope = Scope::create(['project_id' => $project->id, 'name' => $workbook->scope, 'sort_order' => $index + 1]);

                $this->insertActivities($scope, $workbook, $project->planned_start_date->toDateString());
                $recalculator->recalculate($scope);

                array_push($mismatches, ...$this->mismatches($scope, $workbook));
            }

            if ($mismatches !== []) {
                throw new RuntimeException("Import does not match the workbook:\n".implode("\n", $mismatches));
            }
        });
    }

    /**
     * Inserted directly: planned_day and planned_date are not fillable. The placeholder date is
     * overwritten by the recalculation in the same transaction.
     */
    private function insertActivities(Scope $scope, ScheduleWorkbook $workbook, string $placeholderDate): void
    {
        $ids = [];

        foreach ($workbook->activities as $row => $activity) {
            $ids[$row] = DB::table('activities')->insertGetId([
                'scope_id' => $scope->id,
                'description' => $activity['description'],
                'offset_days' => $activity['offset_days'],
                'date_adjustment_days' => $activity['date_adjustment_days'],
                'adjustment_reason' => $activity['adjustment_reason'],
                'is_break' => $activity['is_break'],
                'planned_date' => $placeholderDate,
                'actual_date' => $activity['actual_date'],
                'sort_order' => $row,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($workbook->activities as $row => $activity) {
            if ($activity['predecessor_row'] === null) {
                continue;
            }

            $predecessorId = $ids[$activity['predecessor_row']]
                ?? throw new RuntimeException("{$workbook->scope} row {$row} refers to C{$activity['predecessor_row']}, which is not an activity row.");

            DB::table('activities')->where('id', $ids[$row])->update(['predecessor_id' => $predecessorId]);
        }
    }

    /**
     * @return list<string>
     */
    private function mismatches(Scope $scope, ScheduleWorkbook $workbook): array
    {
        $stored = DB::table('activities')->where('scope_id', $scope->id)->get()->keyBy('sort_order');
        $mismatches = [];

        foreach ($workbook->activities as $row => $activity) {
            $actual = $stored[$row];

            if ((int) $actual->planned_day !== $activity['expected_day'] || $actual->planned_date !== $activity['expected_date']) {
                $mismatches[] = sprintf(
                    '  %s row %d [%s]: expected day %d / %s, computed day %d / %s',
                    $workbook->scope, $row, $activity['description'],
                    $activity['expected_day'], $activity['expected_date'], $actual->planned_day, $actual->planned_date,
                );
            }
        }

        if ($stored->count() !== count($workbook->activities)) {
            $mismatches[] = "  {$workbook->scope}: {$stored->count()} activities stored, ".count($workbook->activities).' in the workbook';
        }

        return $mismatches;
    }
}
