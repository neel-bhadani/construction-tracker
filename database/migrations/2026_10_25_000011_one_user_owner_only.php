<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One account, its own data. Roles and approval go: every account can do everything with the
 * projects it owns and nothing with anyone else's. Accounts that were waiting for approval become
 * ordinary accounts, because approved_at is no longer read.
 *
 * Existing projects need an owner. Name the account when migrating a database that has projects:
 *
 *   PROJECT_OWNER_EMAIL=someone@example.com php artisan migrate
 *
 * The email is read from the environment, not written here, so no account is baked into the code.
 * Each data step checks its own counts and aborts if anything does not match.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $before = $this->counts();
        $owner = $this->ownerForExistingProjects();

        // 1. projects get an owner; a name is unique among one owner's projects, not everyone's
        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('id')->constrained('users')->restrictOnDelete();
        });
        DB::table('projects')->update(['owner_id' => $owner]);
        $this->check(DB::table('projects')->whereNull('owner_id')->count() === 0, 'a project has no owner');
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('owner_id')->nullable(false)->change();
            $table->dropUnique(['active_name']);
            $table->unique(['owner_id', 'active_name']);
        });

        // 2. users: a phone number; no role, no approval
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('email');
            $table->dropColumn(['role', 'approved_at']);
        });

        $after = $this->counts();
        $this->check($before === $after, 'row counts changed: '.json_encode(compact('before', 'after')));
    }

    /**
     * Reverse the migrations. Every account comes back as an approved admin: until now each could
     * do everything with its own data, and admin is the role that could.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'editor', 'viewer'])->default('viewer')->after('password');
            $table->timestamp('approved_at')->nullable()->after('role');
            $table->dropColumn('phone');
        });
        DB::table('users')->update(['role' => 'admin', 'approved_at' => now()]);

        // the foreign key first: MySQL uses the (owner_id, active_name) index for it.
        // Fails if two owners have a project of the same name: the old rule allowed only one.
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->dropUnique(['owner_id', 'active_name']);
            $table->unique('active_name');
            $table->dropColumn('owner_id');
        });
    }

    /** The id of the account named by PROJECT_OWNER_EMAIL, or null when there is nothing to own. */
    private function ownerForExistingProjects(): ?int
    {
        if (DB::table('projects')->count() === 0) {
            return null;
        }

        $email = trim((string) env('PROJECT_OWNER_EMAIL'));
        $this->check($email !== '', 'the database has projects; set PROJECT_OWNER_EMAIL to the account that owns them');

        $id = DB::table('users')->where('email', $email)->value('id');
        $this->check($id !== null, "no account has the email [{$email}]");

        return $id;
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $tables = ['users', 'projects', 'buildings', 'activities', 'activity_revisions', 'project_revisions', 'building_revisions'];

        return array_combine($tables, array_map(fn (string $table) => DB::table($table)->count(), $tables));
    }

    private function check(bool $condition, string $problem): void
    {
        if (! $condition) {
            throw new RuntimeException("Owner-only migration stopped: {$problem}.");
        }
    }
};
