<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Services\ProjectEditor;
use App\Services\WorkIds;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Two creates at once in one project. While one transaction holds the project's next Work ID, a
 * second connection cannot take one: it waits on the project row until the first commits, then
 * reads the counter the first left. The data is committed for real, so this test does not use
 * RefreshDatabase's transaction; it resets the test database before and after instead.
 */
class WorkIdConcurrencyTest extends TestCase
{
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh');
        config(['database.connections.second' => config('database.connections.'.config('database.default'))]);
        $this->project = app(ProjectEditor::class)->createProject(
            ['name' => 'Race', 'details' => null, 'planned_start_date' => '2026-04-05'], $this->owner());
    }

    protected function tearDown(): void
    {
        DB::connection('second')->disconnect();
        // leave the test database fully migrated and empty for whatever runs next
        Artisan::call('migrate:fresh');
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    public function test_a_second_create_waits_for_the_first_and_gets_the_next_number(): void
    {
        $second = DB::connection('second');
        $second->statement('SET SESSION innodb_lock_wait_timeout = 1');

        DB::beginTransaction();
        $first = app(WorkIds::class)->take($this->project->id);

        // the second connection cannot take a number while the first holds the counter
        $second->beginTransaction();
        try {
            $second->table('projects')->where('id', $this->project->id)->lockForUpdate()->first();
            $this->fail('The second connection took the project row while the first held it.');
        } catch (QueryException $exception) {
            $this->assertSame(1205, $exception->errorInfo[1], 'Expected a lock wait timeout.');
        }
        $second->rollBack();

        DB::commit();

        // once the first has committed, the second reads its counter and goes on from there
        $second->beginTransaction();
        $counter = $second->table('projects')->where('id', $this->project->id)->lockForUpdate()->value('next_activity_number');
        $second->rollBack();

        $this->assertSame([1 => 'R-001'], $first);
        $this->assertSame(1, (int) $counter);

        DB::transaction(fn () => $this->assertSame([2 => 'R-002', 3 => 'R-003'], app(WorkIds::class)->take($this->project->id, 2)));
    }
}
