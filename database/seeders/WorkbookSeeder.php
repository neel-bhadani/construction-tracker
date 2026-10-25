<?php

namespace Database\Seeders;

use App\Enums\BuildingType;
use App\Import\ScheduleWorkbook;
use App\Models\Building;
use App\Models\Project;
use App\Models\User;
use App\Services\PlanWriter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One-off import of the client's project and its three timelines from the client's workbooks
 * (CLIENT_WORKBOOKS_DIR, never the repository): Tower A
 * and Tower B as buildings, Overall Timeline as the project's common work. The chain is parsed
 * from the formulas, then recalculated and compared row by row with the workbook's own cached
 * dates. A single mismatch rolls the whole import back.
 *
 * The project belongs to the account passed as `owner`, else to the account named by
 * SEED_OWNER_EMAIL; with neither, nothing is imported.
 */
class WorkbookSeeder extends Seeder
{
    public const PROJECT_NAME = 'Shaligram Vrundavan';

    private const TYPES = [
        'Tower A' => BuildingType::Building,
        'Tower B' => BuildingType::Building,
        'Overall Timeline' => BuildingType::CommonWork,
    ];

    public function run(PlanWriter $writer, ?User $owner = null): void
    {
        $owner ??= User::where('email', (string) env('SEED_OWNER_EMAIL'))->first()
            ?? throw new RuntimeException('Name the account that owns the imported project: SEED_OWNER_EMAIL=someone@example.com.');
        $workbooks = array_map(ScheduleWorkbook::read(...), array_keys(ScheduleWorkbook::SOURCES));

        $startDates = array_unique(array_column($workbooks, 'startDate'));
        if (count($startDates) !== 1) {
            throw new RuntimeException('Workbooks disagree on the start date: '.implode(', ', $startDates));
        }

        DB::transaction(function () use ($workbooks, $startDates, $writer, $owner) {
            if ($owner->projects()->withTrashed()->where('name', self::PROJECT_NAME)->exists()) {
                throw new RuntimeException('['.self::PROJECT_NAME.'] has already been imported.');
            }

            $project = Project::create(['owner_id' => $owner->id, 'name' => self::PROJECT_NAME, 'planned_start_date' => $startDates[0]]);
            $mismatches = [];

            foreach ($workbooks as $index => $workbook) {
                $building = Building::create([
                    'project_id' => $project->id,
                    'name' => $workbook->scope,
                    'type' => self::TYPES[$workbook->scope],
                    'planned_start_date' => $workbook->startDate,
                    'sort_order' => $index + 1,
                ]);

                $writer->write($building, $workbook->plan(withActualDates: true));

                array_push($mismatches, ...$workbook->mismatches($building, compareDates: true));
            }

            if ($mismatches !== []) {
                throw new RuntimeException("Import does not match the workbook:\n".implode("\n", $mismatches));
            }
        });
    }
}
