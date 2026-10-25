<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * The migration to one account and its own data, run against the schema as it stood before:
 * admins, an editor, a viewer and an account still waiting for approval, and two projects.
 */
class OwnerOnlyMigrationTest extends TestCase
{
    private const MIGRATION = '2026_10_25_000011_one_user_owner_only.php';

    protected function tearDown(): void
    {
        unset($_SERVER['PROJECT_OWNER_EMAIL'], $_ENV['PROJECT_OWNER_EMAIL']);

        // leave the test database fully migrated and empty for whatever runs next
        Artisan::call('migrate:fresh');
        RefreshDatabaseState::$migrated = false;

        parent::tearDown();
    }

    public function test_projects_go_to_the_named_account_and_roles_and_approval_go(): void
    {
        $this->migrateToTheOldSchema();
        $this->seedOldData();
        $before = $this->counts();

        $this->ownerEmail('admin@example.test');
        $this->runMigration();

        $this->assertSame([[1, 1], [2, 1]], DB::table('projects')->orderBy('id')->get()->map(fn ($p) => [$p->id, $p->owner_id])->all());
        $this->assertFalse(Schema::hasColumn('users', 'role'));
        $this->assertFalse(Schema::hasColumn('users', 'approved_at'));
        $this->assertTrue(Schema::hasColumn('users', 'phone'));
        $this->assertSame($before, $this->counts());

        // the account that was waiting can now sign in like any other
        $this->post('/login', ['email' => 'pending@example.test', 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_a_database_with_projects_needs_the_owner_named(): void
    {
        $this->migrateToTheOldSchema();
        $this->seedOldData();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('set PROJECT_OWNER_EMAIL');
        Artisan::call('migrate', ['--path' => database_path('migrations/'.self::MIGRATION), '--realpath' => true]);
    }

    public function test_an_unknown_owner_is_refused_and_nothing_changes(): void
    {
        $this->migrateToTheOldSchema();
        $this->seedOldData();
        $this->ownerEmail('nobody@example.test');

        try {
            Artisan::call('migrate', ['--path' => database_path('migrations/'.self::MIGRATION), '--realpath' => true]);
            $this->fail('Expected the migration to stop.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('no account has the email [nobody@example.test]', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('projects', 'owner_id'));
        $this->assertTrue(Schema::hasColumn('users', 'role'));
    }

    public function test_a_database_without_projects_needs_no_owner(): void
    {
        $this->migrateToTheOldSchema();

        $this->runMigration();

        $this->assertTrue(Schema::hasColumn('projects', 'owner_id'));
    }

    public function test_rolling_back_makes_every_account_an_approved_admin(): void
    {
        $this->migrateToTheOldSchema();
        $this->seedOldData();
        $this->ownerEmail('admin@example.test');
        $this->runMigration();

        Artisan::call('migrate:rollback', ['--path' => database_path('migrations/'.self::MIGRATION), '--realpath' => true]);

        $this->assertFalse(Schema::hasColumn('projects', 'owner_id'));
        $this->assertSame(['admin'], DB::table('users')->distinct()->pluck('role')->all());
        $this->assertSame(0, DB::table('users')->whereNull('approved_at')->count());
    }

    private function migrateToTheOldSchema(): void
    {
        $paths = array_values(array_filter(
            glob(database_path('migrations/*.php')),
            fn (string $path) => basename($path) < self::MIGRATION,
        ));

        Artisan::call('migrate:fresh', ['--path' => $paths, '--realpath' => true]);

        $this->assertTrue(Schema::hasColumn('users', 'role'));
    }

    private function runMigration(): void
    {
        $this->assertSame(0, Artisan::call('migrate', ['--path' => database_path('migrations/'.self::MIGRATION), '--realpath' => true]));
    }

    private function ownerEmail(string $email): void
    {
        $_SERVER['PROJECT_OWNER_EMAIL'] = $_ENV['PROJECT_OWNER_EMAIL'] = $email;
    }

    private function seedOldData(): void
    {
        $now = '2026-10-25 12:00:00';
        $password = bcrypt('password');
        DB::table('users')->insert([
            ['id' => 1, 'name' => 'Admin', 'email' => 'admin@example.test', 'password' => $password, 'role' => 'admin', 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Editor', 'email' => 'editor@example.test', 'password' => $password, 'role' => 'editor', 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 3, 'name' => 'Viewer', 'email' => 'viewer@example.test', 'password' => $password, 'role' => 'viewer', 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 4, 'name' => 'Pending', 'email' => 'pending@example.test', 'password' => $password, 'role' => 'viewer', 'approved_at' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('projects')->insert([
            ['id' => 1, 'name' => 'Shaligram Vrundavan', 'planned_start_date' => '2026-04-05', 'created_by' => null, 'created_at' => $now, 'updated_at' => $now],
            ['id' => 2, 'name' => 'Second Project', 'planned_start_date' => '2027-01-10', 'created_by' => 2, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('buildings')->insert([
            ['id' => 1, 'project_id' => 1, 'name' => 'Tower A', 'type' => 'building', 'planned_start_date' => '2026-04-05', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
        DB::table('activities')->insert([
            ['id' => 1, 'building_id' => 1, 'description' => 'Root', 'offset_days' => 10, 'planned_day' => 10, 'planned_date' => '2026-04-15', 'actual_date' => '2026-04-14', 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function counts(): array
    {
        return [
            'users' => DB::table('users')->orderBy('id')->pluck('email')->all(),
            'projects' => DB::table('projects')->orderBy('id')->pluck('name')->all(),
            'activities' => DB::table('activities')->get(['id', 'planned_date', 'actual_date'])->map(fn ($row) => (array) $row)->all(),
        ];
    }
}
