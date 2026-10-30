<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * The Work ID and Common activities migration, run against the schema as it stood before, on
 * hand-made data: two owners, a project with its timelines out of order, a deleted project, a
 * project with no common timeline, and two projects whose initials clash.
 */
class WorkIdsMigrationTest extends TestCase
{
    private const MIGRATION = '2026_10_30_000015_work_ids_and_common_activities.php';

    protected function tearDown(): void
    {
        // leave the test database fully migrated and empty for whatever runs next
        Artisan::call('migrate:fresh');
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    public function test_codes_work_ids_and_common_activities(): void
    {
        $this->migrateToTheOldSchema();
        $this->seedOldData();
        $before = $this->activities();

        $this->runMigration();

        // initials; a clash among one owner's live projects gets a number; a deleted project does not count
        $this->assertSame([
            [1, 'SV', 'SV', 6], [2, 'SV', null, 1], [3, 'SV2', 'SV2', 1], [4, 'T', 'T', 0], [5, 'SV', 'SV', 0],
        ], DB::table('projects')->orderBy('id')->get()->map(fn ($p) => [$p->id, $p->code, $p->active_code, $p->next_activity_number])->all());

        // project 1: common first (31, 32), then Tower A in list order (11 before 12), Tower B (21),
        // then the deleted Tower Old (41). Every project counts from 1.
        $this->assertSame([
            11 => 'SV-003', 12 => 'SV-004', 21 => 'SV-005', 31 => 'SV-001', 32 => 'SV-002', 41 => 'SV-006',
            51 => 'SV-001', 61 => 'SV2-001',
        ], $this->workIds());
        $this->assertSame([3, 4, 5, 1, 2, 6], DB::table('activities')->whereIn('id', [11, 12, 21, 31, 32, 41])->orderBy('id')->pluck('work_number')->all());

        // renamed in place, same id; the project without one gets an empty one
        $this->assertSame('Common activities', DB::table('buildings')->where('id', 3)->value('name'));
        $added = DB::table('buildings')->where('project_id', 4)->where('type', 'common_work')->sole();
        $this->assertSame(['Common activities', '2027-01-01', 0], [$added->name, $added->planned_start_date, $added->sort_order]);
        $this->assertSame(0, DB::table('activities')->where('building_id', $added->id)->count());
        // the deleted project keeps its deleted common timeline, renamed, and gets no new one
        $this->assertSame(1, DB::table('buildings')->where('project_id', 2)->count());

        // nothing about the activities themselves changed
        $this->assertSame($before, $this->activities());

        // one live common timeline per project from now on
        $this->expectException(QueryException::class);
        DB::table('buildings')->insert(['project_id' => 1, 'name' => 'Site works', 'type' => 'common_work', 'planned_start_date' => '2026-04-05']);
    }

    public function test_rolling_back_restores_names_and_removes_what_it_added(): void
    {
        $this->migrateToTheOldSchema();
        $this->seedOldData();
        $buildings = DB::table('buildings')->orderBy('id')->get(['id', 'project_id', 'name', 'type', 'deleted_at'])->map(fn ($b) => (array) $b)->all();
        $before = $this->activities();

        $this->runMigration();
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => database_path('migrations/'.self::MIGRATION), '--realpath' => true]));

        $this->assertSame($buildings, DB::table('buildings')->orderBy('id')->get(['id', 'project_id', 'name', 'type', 'deleted_at'])->map(fn ($b) => (array) $b)->all());
        $this->assertSame($before, $this->activities());
        $this->assertFalse(Schema::hasColumn('projects', 'code'));
        $this->assertFalse(Schema::hasColumn('activities', 'work_id'));
        $this->assertSame(0, DB::table('building_revisions')->count());
    }

    public function test_two_common_timelines_in_one_project_stop_it_before_anything_changes(): void
    {
        $this->migrateToTheOldSchema();
        $this->seedOldData();
        DB::table('buildings')->insert(['id' => 9, 'project_id' => 1, 'name' => 'Site works', 'type' => 'common_work', 'planned_start_date' => '2026-04-05', 'sort_order' => 9]);

        try {
            Artisan::call('migrate', ['--path' => database_path('migrations/'.self::MIGRATION), '--realpath' => true]);
            $this->fail('The migration ran.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('these projects have more than one common timeline: 1', $exception->getMessage());
        }
        $this->assertFalse(Schema::hasColumn('projects', 'code'));
    }

    private function migrateToTheOldSchema(): void
    {
        $paths = array_values(array_filter(
            glob(database_path('migrations/*.php')),
            fn (string $path) => basename($path) < self::MIGRATION,
        ));

        Artisan::call('migrate:fresh', ['--path' => $paths, '--realpath' => true]);

        $this->assertFalse(Schema::hasColumn('projects', 'code'));
    }

    private function runMigration(): void
    {
        $this->assertSame(0, Artisan::call('migrate', ['--path' => database_path('migrations/'.self::MIGRATION), '--realpath' => true]));
    }

    private function seedOldData(): void
    {
        $now = '2026-10-29 12:00:00';
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Someone else', 'email' => 'else@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('projects')->insert([
            ['id' => 1, 'owner_id' => 1, 'name' => 'Shaligram Vrundavan', 'planned_start_date' => '2026-04-05', 'deleted_at' => null],
            // deleted: its code may repeat a live one's
            ['id' => 2, 'owner_id' => 1, 'name' => 'Shaligram Vrundavan', 'planned_start_date' => '2026-04-05', 'deleted_at' => $now],
            // the same initials as project 1, same owner: SV2
            ['id' => 3, 'owner_id' => 1, 'name' => 'Sunrise Villas', 'planned_start_date' => '2026-04-05', 'deleted_at' => null],
            ['id' => 4, 'owner_id' => 1, 'name' => 'test', 'planned_start_date' => '2027-01-01', 'deleted_at' => null],
            // another owner's SV is no clash
            ['id' => 5, 'owner_id' => 2, 'name' => 'Silver Valley', 'planned_start_date' => '2026-04-05', 'deleted_at' => null],
        ]);
        $building = fn (int $id, int $project, string $name, string $type, int $sort, ?string $deleted = null) => [
            'id' => $id, 'project_id' => $project, 'name' => $name, 'type' => $type, 'planned_start_date' => '2026-04-05', 'sort_order' => $sort, 'deleted_at' => $deleted,
        ];
        DB::table('buildings')->insert([
            // project 1: the common timeline is last in the list, and a deleted tower comes between
            $building(1, 1, 'Tower A', 'building', 1),
            $building(4, 1, 'Tower Old', 'building', 2, $now),
            $building(2, 1, 'Tower B', 'building', 3),
            $building(3, 1, 'Overall Timeline', 'common_work', 4),
            // project 2, deleted with its common timeline
            $building(5, 2, 'Overall Timeline', 'common_work', 1, $now),
            $building(6, 3, 'Overall Timeline', 'common_work', 1),
            $building(7, 4, 'A', 'building', 1),
            $building(8, 5, 'Block 1', 'building', 1),
        ]);
        $activity = fn (int $id, int $building, int $sort) => [
            'id' => $id, 'building_id' => $building, 'description' => "Activity {$id}", 'planned_date' => '2026-04-05', 'sort_order' => $sort,
        ];
        DB::table('activities')->insert([
            // Tower A: list order differs from id order
            $activity(12, 1, 2), $activity(11, 1, 1),
            $activity(21, 2, 1),
            $activity(31, 3, 1), $activity(32, 3, 2),
            $activity(41, 4, 1),
            $activity(51, 5, 1),
            $activity(61, 6, 1),
        ]);
    }

    /**
     * @return array<int, string> work_id by activity id
     */
    private function workIds(): array
    {
        return DB::table('activities')->orderBy('id')->pluck('work_id', 'id')->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activities(): array
    {
        return DB::table('activities')->orderBy('id')
            ->get(['id', 'building_id', 'description', 'planned_date', 'actual_date', 'sort_order'])
            ->map(fn ($row) => (array) $row)->all();
    }
}
