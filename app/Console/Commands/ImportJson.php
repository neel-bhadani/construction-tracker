<?php

namespace App\Console\Commands;

use App\Exceptions\PlanImportException;
use App\Models\User;
use App\Services\PlanImporter;
use Illuminate\Console\Command;
use JsonException;

/**
 * Step 2 of the import: the JSON from `timeline:excel-to-json` (or a hand-corrected copy) into one
 * new project for --owner. Everything is checked first; then it is written in one transaction and
 * read back against the file before it is committed. --dry-run does the same and rolls back.
 */
class ImportJson extends Command
{
    protected $signature = 'timeline:import-json
                            {file : The JSON file}
                            {--owner= : The email of the account that will own the project}
                            {--dry-run : Do every insert and check in a transaction, then roll it all back}
                            {--as= : Another name for the project, e.g. when the owner already has one by that name}';

    protected $description = 'Import a project from an import JSON file (step 2 of 2)';

    public function handle(PlanImporter $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->line($dryRun ? '<options=bold>DRY RUN: nothing will be written</>' : '<options=bold>IMPORT</>');

        $file = $this->argument('file');
        if (! is_readable($file)) {
            $this->error("Cannot read [{$file}].");

            return self::FAILURE;
        }

        $owner = User::where('email', (string) $this->option('owner'))->first();
        if ($owner === null) {
            $this->error($this->option('owner') ? "No account has the email [{$this->option('owner')}]." : 'Name the owner: --owner=you@example.com');

            return self::FAILURE;
        }

        $contents = file_get_contents($file);
        try {
            $document = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error("[{$file}] is not valid JSON: {$exception->getMessage()}");

            return self::FAILURE;
        }
        if (! is_array($document)) {
            $this->error("[{$file}] does not hold an import document.");

            return self::FAILURE;
        }

        try {
            $result = $importer->import($document, $owner, basename($file), hash('sha256', $contents), $this->option('as'), $dryRun);
        } catch (PlanImportException $exception) {
            $this->error('Import stopped. '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("Project [{$result->projectName}] for [{$owner->email}]");
        $this->table(
            ['Building', 'Type', 'Activities', 'Breaks', 'Actual dates'],
            array_map(fn (array $b) => [$b['name'], $b['type'], $b['activities'], $b['breaks'], $b['actuals']], $result->buildings),
        );
        $this->line("Planned dates equal to the file (Excel): {$result->datesChecked} of {$result->datesChecked}");
        $this->line("Figures equal to `expected` (%, lead, finish, forecasts): {$result->figuresChecked} of {$result->figuresChecked}");

        $this->newLine();
        if ($dryRun) {
            $this->line('<options=bold>DRY RUN rolled back: nothing written.</>');
            $this->line('Every row above was really inserted and read back inside a transaction, then undone; a real run does exactly this and commits.');
        } else {
            $this->info("Imported as project {$result->projectId}, logged in plan_imports.");
        }

        return self::SUCCESS;
    }
}
