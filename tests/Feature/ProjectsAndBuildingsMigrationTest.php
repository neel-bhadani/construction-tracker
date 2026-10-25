<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The migration from one fixed set of scopes to projects and buildings. It is run against the
 * schema as it stood before, holding data shaped like the real thing — actual dates, notes,
 * predecessor chains, revisions, a revision whose activity is already deleted, a project-date
 * revision — and every row is checked afterwards.
 */
class ProjectsAndBuildingsMigrationTest extends TestCase
{
    private const MIGRATION = '2026_10_25_000010_projects_and_buildings.php';

    protected function tearDown(): void
    {
        // leave the test database fully migrated and empty for whatever runs next
        Artisan::call('migrate:fresh');
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    public function test_every_row_actual_date_and_revision_survives_with_its_id(): void
    {
        $this->migrateToTheOldSchema();
        $this->seedOldData();
        $before = $this->oldSnapshot();

        $this->runNewMigration();

        // timelines: the same ids, names, order and project, now with their own start dates
        $this->assertFalse(Schema::hasTable('scopes'));
        $this->assertSame([
            [1, 1, 'Tower A', 'building', '2026-04-05', '2026-05-01', 1, null],
            [2, 1, 'Tower B', 'building', '2026-04-05', '2026-05-01', 2, null],
            [3, 1, 'Overall Timeline', 'common_work', '2026-04-05', '2026-05-01', 3, null],
            [4, 2, 'Block 1', 'building', '2027-01-10', null, 1, null],
        ], DB::table('buildings')->orderBy('id')->get()->map(fn ($b) => [
            $b->id, $b->project_id, $b->name, $b->type, $b->planned_start_date, $b->actual_start_date, $b->sort_order, $b->deleted_at,
        ])->all());

        // activities: every column of every row, scope_id now building_id
        $this->assertSame($before['activities'], $this->rows('activities', fn ($row) => $this->renameKey($row, 'building_id', 'scope_id')));

        // audit trails: every row as it was; project revisions gain an empty note
        $this->assertSame($before['activity_revisions'], $this->rows('activity_revisions'));
        $this->assertSame($before['project_revisions'], $this->rows('project_revisions', fn ($row) => $this->without($row, 'note')));
        $this->assertSame(0, DB::table('project_revisions')->whereNotNull('note')->count());
        $this->assertSame($before['users'], $this->rows('users'));

        // projects keep their rows; the actual start has moved to the buildings
        $this->assertFalse(Schema::hasColumn('projects', 'actual_start_date'));
        $this->assertSame([
            [1, 'Shaligram Vrundavan', '2026-04-05', 'Asia/Kolkata', null, null],
            [2, 'Second Project', '2027-01-10', 'Asia/Kolkata', null, null],
        ], DB::table('projects')->orderBy('id')->get()->map(fn ($p) => [$p->id, $p->name, $p->planned_start_date, $p->timezone, $p->created_by, $p->deleted_at])->all());

        $this->assertSame(0, DB::table('building_revisions')->count());
    }

    public function test_rolling_back_and_migrating_again_changes_nothing(): void
    {
        $this->migrateToTheOldSchema();
        $this->seedOldData();
        $before = $this->oldSnapshot();

        $this->runNewMigration();
        $migrated = [$this->rows('buildings'), $this->rows('activities'), $this->rows('activity_revisions'), $this->rows('project_revisions')];

        Artisan::call('migrate:rollback', ['--path' => database_path('migrations/'.self::MIGRATION), '--realpath' => true]);
        $this->assertTrue(Schema::hasTable('scopes'));
        $this->assertSame($before, $this->oldSnapshot(), 'Rolling back does not restore the old data exactly.');

        $this->runNewMigration();
        $this->assertSame($migrated, [$this->rows('buildings'), $this->rows('activities'), $this->rows('activity_revisions'), $this->rows('project_revisions')]);
    }

    private function migrateToTheOldSchema(): void
    {
        $paths = array_values(array_filter(
            glob(database_path('migrations/*.php')),
            fn (string $path) => basename($path) < self::MIGRATION,
        ));

        Artisan::call('migrate:fresh', ['--path' => $paths, '--realpath' => true]);

        $this->assertTrue(Schema::hasTable('scopes'));
    }

    private function runNewMigration(): void
    {
        $this->assertSame(0, Artisan::call('migrate', ['--path' => database_path('migrations/'.self::MIGRATION), '--realpath' => true]));
    }

    /**
     * The shape of the real data: two projects, an actual start on the first, actuals, notes,
     * chains, adjustments, a revision whose activity has been deleted, and a project-date change.
     */
    private function seedOldData(): void
    {
        $now = '2026-10-24 12:00:00';
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'x', 'role' => 'admin', 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Editor', 'email' => 'editor@example.test', 'password' => 'x', 'role' => 'editor', 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('projects')->insert([
            ['id' => 1, 'name' => 'Shaligram Vrundavan', 'planned_start_date' => '2026-04-05', 'actual_start_date' => '2026-05-01', 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Second Project', 'planned_start_date' => '2027-01-10', 'actual_start_date' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('scopes')->insert([
            ['id' => 1, 'project_id' => 1, 'name' => 'Tower A', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'project_id' => 1, 'name' => 'Tower B', 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'project_id' => 1, 'name' => 'Overall Timeline', 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'project_id' => 2, 'name' => 'Block 1', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $id = 0;
        foreach ([1 => 6, 2 => 5, 3 => 4, 4 => 3] as $scope => $count) {
            $first = $id + 1;
            for ($i = 0; $i < $count; $i++) {
                $id++;
                DB::table('activities')->insert([
                    'id' => $id, 'scope_id' => $scope, 'description' => "S{$scope} activity {$i}",
                    'predecessor_id' => $i === 0 ? null : ($i === 3 ? $first : $id - 1),
                    'offset_days' => 10 + $i, 'date_adjustment_days' => $i === 2 ? 7 : 0,
                    'adjustment_reason' => $i === 2 ? 'carried from original sheet' : null, 'is_break' => $i === 1,
                    'planned_day' => 100 + $id, 'planned_date' => '2027-01-01', 'actual_date' => $i === 0 ? '2026-09-20' : null,
                    'sort_order' => $i + 1, 'notes' => $i === 0 ? 'Poured on time' : null, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        DB::table('activity_revisions')->insert([
            ['id' => 1, 'activity_id' => 7, 'activity_snapshot_id' => 7, 'user_id' => 2, 'field' => 'actual_date', 'old_value' => null, 'new_value' => '2026-10-24', 'created_at' => $now],
            ['id' => 2, 'activity_id' => 7, 'activity_snapshot_id' => 7, 'user_id' => 2, 'field' => 'actual_date', 'old_value' => '2026-10-24', 'new_value' => null, 'created_at' => $now],
            ['id' => 3, 'activity_id' => null, 'activity_snapshot_id' => 999, 'user_id' => 1, 'field' => 'deleted', 'old_value' => 'A deleted activity', 'new_value' => null, 'created_at' => $now],
        ]);
        DB::table('project_revisions')->insert([
            ['id' => 1, 'project_id' => 1, 'user_id' => 1, 'field' => 'actual_start_date', 'old_value' => null, 'new_value' => '2026-05-01', 'created_at' => $now],
        ]);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function oldSnapshot(): array
    {
        return [
            'activities' => $this->rows('activities'),
            'activity_revisions' => $this->rows('activity_revisions'),
            'project_revisions' => $this->rows('project_revisions'),
            'users' => $this->rows('users'),
            'projects' => $this->rows('projects'),
            'scopes' => Schema::hasTable('scopes') ? $this->rows('scopes') : [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, ?callable $map = null): array
    {
        return DB::table($table)->orderBy('id')->get()
            ->map(fn ($row) => $map ? $map((array) $row) : (array) $row)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function renameKey(array $row, string $from, string $to): array
    {
        $renamed = [];
        foreach ($row as $key => $value) {
            $renamed[$key === $from ? $to : $key] = $value;
        }

        return $renamed;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function without(array $row, string $key): array
    {
        unset($row[$key]);

        return $row;
    }
}
