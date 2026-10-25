<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The floors migration adds tables and a column and changes no existing row.
 */
class FloorsMigrationTest extends TestCase
{
    private const MIGRATION = '2026_10_25_000012_create_floors_table.php';

    protected function tearDown(): void
    {
        // leave the test database fully migrated and empty for whatever runs next
        Artisan::call('migrate:fresh');
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    public function test_existing_activities_are_untouched_and_on_no_floor_and_it_rolls_back(): void
    {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn (string $path) => basename($path) < self::MIGRATION));
        Artisan::call('migrate:fresh', ['--path' => $paths, '--realpath' => true]);

        $now = '2026-10-25 12:00:00';
        DB::table('users')->insert(['id' => 1, 'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('projects')->insert(['id' => 1, 'owner_id' => 1, 'name' => 'P', 'planned_start_date' => '2026-01-01', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('buildings')->insert(['id' => 1, 'project_id' => 1, 'name' => 'Tower A', 'type' => 'building', 'planned_start_date' => '2026-01-01', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('activities')->insert([
            ['id' => 1, 'building_id' => 1, 'description' => 'GF SLAB', 'offset_days' => 10, 'planned_day' => 10, 'planned_date' => '2026-01-11', 'actual_date' => '2026-01-10', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'building_id' => 1, 'description' => '1st SLAB', 'offset_days' => 20, 'planned_day' => 30, 'planned_date' => '2026-01-31', 'actual_date' => null, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $before = DB::table('activities')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();

        $this->assertSame(0, Artisan::call('migrate', ['--path' => database_path('migrations/'.self::MIGRATION), '--realpath' => true]));

        $this->assertTrue(Schema::hasTable('floors'));
        $after = DB::table('activities')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $this->assertSame([null, null], array_column($after, 'floor_id'));
        $this->assertSame($before, array_map(function (array $row) {
            unset($row['floor_id']);

            return $row;
        }, $after));

        Artisan::call('migrate:rollback', ['--path' => database_path('migrations/'.self::MIGRATION), '--realpath' => true]);
        $this->assertFalse(Schema::hasTable('floors'));
        $this->assertFalse(Schema::hasColumn('activities', 'floor_id'));
        $this->assertSame($before, DB::table('activities')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
    }
}
