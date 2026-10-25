<?php

namespace Tests;

use App\Import\ImportUploads;
use App\Import\ScheduleWorkbook;
use App\Models\User;
use Database\Seeders\WorkbookSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** The account that owns the projects a test sets up; made on first use. */
    private ?User $owner = null;

    protected function owner(): User
    {
        return $this->owner ??= User::factory()->create(['name' => 'The Owner']);
    }

    /**
     * The client's workbooks are not in the repository. Tests that need them are skipped, not
     * failed, where CLIENT_WORKBOOKS_DIR does not hold all three.
     */
    protected function requireClientWorkbooks(): void
    {
        if (! ScheduleWorkbook::available()) {
            $this->markTestSkipped('The client workbooks are not in CLIENT_WORKBOOKS_DIR ('.ScheduleWorkbook::directory().').');
        }
    }

    /** Import the client's project from its workbooks for owner(), or skip the test where they are missing. */
    protected function seedClientWorkbooks(): void
    {
        $this->requireClientWorkbooks();
        $this->app->make(WorkbookSeeder::class)->setContainer($this->app)->__invoke(['owner' => $this->owner()]);
    }

    /**
     * RefreshDatabase wipes whatever it points at. A cached config or a typo
     * (construction_tracker_testing is someone else's database) must not reach it.
     *
     * Guarded here rather than in beforeRefreshingDatabase(), which the trait's own
     * empty implementation would silently override.
     */
    protected function setUpTraits()
    {
        $database = config('database.connections.'.config('database.default').'.database');

        if ($database !== 'construction_tracker_test') {
            throw new RuntimeException("Refusing to run tests against database [{$database}]; only construction_tracker_test is allowed.");
        }

        // uploaded workbooks go to a throwaway directory, never the app's storage
        $this->app->instance(ImportUploads::class, new ImportUploads(sys_get_temp_dir().'/timeline-test-imports-'.getmypid()));

        return parent::setUpTraits();
    }
}
