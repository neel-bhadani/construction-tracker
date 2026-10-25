<?php

namespace App\Console\Commands;

use App\Enums\BuildingType;
use App\Import\ChartSheet;
use App\Import\PlanDocument;
use App\Import\PlanDocumentValidator;
use App\Import\ScheduleWorkbook;
use Database\Seeders\WorkbookSeeder;
use Illuminate\Console\Command;
use UnexpectedValueException;

/**
 * Step 1 of the import: the client's workbooks to one JSON file, outside the repository
 * (storage/app/private is git-ignored). Read the floor guesses it prints, correct them in the JSON
 * if needed, then run step 2, `timeline:import-json`.
 *
 * Everything is parsed from the formulas, never evaluated: planned dates and the `expected` figures
 * are Excel's own cached results, so step 2 can check its recalculation against them.
 */
class ExcelToJson extends Command
{
    protected $signature = 'timeline:excel-to-json
                            {--dir= : Where the workbooks are (default CLIENT_WORKBOOKS_DIR)}
                            {--out= : The JSON file to write (default storage/app/private/imports/<today>/project.json)}
                            {--force : Overwrite the JSON file if it is already there}';

    protected $description = 'Read the client\'s workbooks into an import JSON file (step 1 of 2)';

    public function handle(PlanDocumentValidator $validator): int
    {
        $dir = rtrim($this->option('dir') ?: ScheduleWorkbook::directory(), '/');
        $out = $this->option('out') ?: storage_path('app/private/imports/'.now()->toDateString().'/project.json');

        if (is_file($out) && ! $this->option('force')) {
            $this->error("[{$out}] is already there, perhaps with your corrections. Use --force to overwrite it, or --out= for another file.");

            return self::FAILURE;
        }

        $buildings = [];
        $expected = [];

        foreach (ScheduleWorkbook::SOURCES as $scope => $file) {
            $path = "{$dir}/{$file}";
            if (! is_readable($path)) {
                $this->error("Cannot read [{$path}]. Nothing was written.");

                return self::FAILURE;
            }

            try {
                $workbook = ScheduleWorkbook::readFile($path, $scope);
                $chart = ChartSheet::figuresOf($path, $scope);
            } catch (UnexpectedValueException $exception) {
                $this->error("{$file}: {$exception->getMessage()} Nothing was written.");

                return self::FAILURE;
            }

            $type = ScheduleWorkbook::TYPES[$scope];
            // common work is the whole site's: no floors are guessed for it
            $buildings[] = PlanDocument::building($workbook, $type, $chart['actualStartDate'], guessFloors: $type === BuildingType::Building);
            $expected[$scope] = PlanDocument::expected($chart);
        }

        $document = [
            'project' => ['name' => WorkbookSeeder::PROJECT_NAME, 'timezone' => config('app.timezone')],
            'buildings' => $buildings,
            'expected' => $expected,
        ];

        $this->floorTable($buildings);

        $problems = $validator->validate($document);
        if ($problems !== []) {
            $this->error('The workbooks do not make a plan that can be imported. Nothing was written:');
            foreach ($problems as $problem) {
                $this->line("  {$problem}");
            }

            return self::FAILURE;
        }

        if (! is_dir(dirname($out))) {
            mkdir(dirname($out), 0775, true);
        }
        file_put_contents($out, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $this->newLine();
        $this->info("Wrote [{$out}].");
        $this->line(sprintf('%d buildings, %d activities, %d floors; every planned date checked against Excel.',
            count($buildings),
            array_sum(array_map(fn (array $b) => count($b['activities']), $buildings)),
            array_sum(array_map(fn (array $b) => count($b['floors']), $buildings)),
        ));
        $this->line('Correct any floor in the JSON (the activity\'s "floor", and the building\'s "floors" list), then:');
        $this->line("  php artisan timeline:import-json {$out} --owner=you@example.com --dry-run");

        return self::SUCCESS;
    }

    /**
     * Every guess, building by building, so they can be checked before step 2.
     *
     * @param  list<array<string, mixed>>  $buildings
     */
    private function floorTable(array $buildings): void
    {
        foreach ($buildings as $building) {
            $this->newLine();
            $this->line("<options=bold>{$building['name']}</> ({$building['type']}): floors guessed from names");

            if ($building['type'] !== BuildingType::Building->value) {
                $this->line('  Common work: every activity is Whole building.');

                continue;
            }

            $this->table(['Row', 'Activity', 'Floor'], array_map(fn (array $a) => [
                $a['row'],
                $a['name'],
                $a['is_break'] ? 'Whole building (break)' : ($a['floor'] ?? 'Whole building'),
            ], $building['activities']));
        }
    }
}
