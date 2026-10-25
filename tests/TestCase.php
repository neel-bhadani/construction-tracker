<?php

namespace Tests;

use App\Import\ImportUploads;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
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
