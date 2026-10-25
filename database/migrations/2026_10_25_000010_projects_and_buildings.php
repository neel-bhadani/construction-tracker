<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Many projects, each with many buildings.
 *
 * `scopes` is renamed to `buildings` in place, so every id — and with it every activity, actual
 * date and revision — stays where it is. Start dates move from the project to each building,
 * copied from the project they belonged to. Each data step checks its own counts and aborts if
 * anything does not match; DDL cannot be rolled back in MySQL, so the checks are what stop a
 * half-done migration from going unnoticed.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $before = $this->counts();

        // 1. projects: the new fields; a name is unique among projects that are not deleted
        Schema::table('projects', function (Blueprint $table) {
            $table->text('details')->nullable()->after('name');
            $table->string('timezone', 64)->default('Asia/Kolkata')->after('actual_start_date');
            $table->foreignId('created_by')->nullable()->after('timezone')->constrained('users')->nullOnDelete();
            $table->softDeletes();
            // NULL once deleted; MySQL lets any number of NULLs share a unique index
            $table->string('active_name', 150)->nullable()->storedAs('IF(deleted_at IS NULL, name, NULL)');
            $table->unique('active_name');
        });

        // 2. scopes become buildings, keeping their ids
        Schema::table('scopes', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropUnique(['project_id', 'name']);
            $table->dropIndex(['project_id', 'sort_order']);
        });
        Schema::rename('scopes', 'buildings');
        Schema::table('buildings', function (Blueprint $table) {
            $table->enum('type', ['building', 'common_work'])->default('building')->after('name');
            $table->date('planned_start_date')->nullable()->after('type');
            $table->date('actual_start_date')->nullable()->after('planned_start_date');
            $table->softDeletes();
            $table->string('active_name', 100)->nullable()->storedAs('IF(deleted_at IS NULL, name, NULL)');

            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['project_id', 'active_name']);
            $table->index(['project_id', 'sort_order']);
        });

        // 3. each building takes its project's start dates; Overall Timeline is site-wide work
        DB::statement('UPDATE buildings b JOIN projects p ON p.id = b.project_id
            SET b.planned_start_date = p.planned_start_date, b.actual_start_date = p.actual_start_date');
        DB::table('buildings')->where('name', 'Overall Timeline')->update(['type' => 'common_work']);
        $this->check(DB::table('buildings')->whereNull('planned_start_date')->count() === 0, 'a building has no planned start date');
        Schema::table('buildings', function (Blueprint $table) {
            $table->date('planned_start_date')->nullable(false)->change();
        });

        // 4. activities point at their building
        Schema::table('activities', function (Blueprint $table) {
            $table->dropForeign(['scope_id']);
            $table->dropIndex(['scope_id', 'sort_order']);
            $table->dropIndex(['scope_id', 'actual_date']);
        });
        Schema::table('activities', function (Blueprint $table) {
            $table->renameColumn('scope_id', 'building_id');
        });
        Schema::table('activities', function (Blueprint $table) {
            $table->foreign('building_id')->references('id')->on('buildings')->cascadeOnDelete();
            $table->index(['building_id', 'sort_order']);
            $table->index(['building_id', 'actual_date']);
        });

        // 5. the audit trail for building fields, and a note on both trails
        Schema::create('building_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('field', 40);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['building_id', 'created_at']);
        });
        Schema::table('project_revisions', function (Blueprint $table) {
            $table->string('note', 255)->nullable()->after('new_value');
        });

        // 6. the actual start now lives on each building
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('actual_start_date');
        });

        $after = $this->counts();
        $this->check($before === $after, 'row counts changed: '.json_encode(compact('before', 'after')));
    }

    /**
     * Reverse the migrations. A project's actual start is taken back from its first building.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->date('actual_start_date')->nullable()->after('planned_start_date');
        });
        DB::statement('UPDATE projects p SET p.actual_start_date = (
            SELECT b.actual_start_date FROM buildings b WHERE b.project_id = p.id ORDER BY b.sort_order, b.id LIMIT 1)');

        Schema::table('project_revisions', function (Blueprint $table) {
            $table->dropColumn('note');
        });
        Schema::dropIfExists('building_revisions');

        Schema::table('activities', function (Blueprint $table) {
            $table->dropForeign(['building_id']);
            $table->dropIndex(['building_id', 'sort_order']);
            $table->dropIndex(['building_id', 'actual_date']);
        });
        Schema::table('activities', function (Blueprint $table) {
            $table->renameColumn('building_id', 'scope_id');
        });

        Schema::table('buildings', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropUnique(['project_id', 'active_name']);
            $table->dropIndex(['project_id', 'sort_order']);
            $table->dropColumn(['active_name', 'type', 'planned_start_date', 'actual_start_date', 'deleted_at']);
        });
        Schema::rename('buildings', 'scopes');
        Schema::table('scopes', function (Blueprint $table) {
            $table->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $table->unique(['project_id', 'name']);
            $table->index(['project_id', 'sort_order']);
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->foreign('scope_id')->references('id')->on('scopes')->cascadeOnDelete();
            $table->index(['scope_id', 'sort_order']);
            $table->index(['scope_id', 'actual_date']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['active_name']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['active_name', 'details', 'timezone', 'deleted_at']);
        });
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $tables = ['projects', 'activities', 'activity_revisions', 'project_revisions', 'users'];
        $counts = array_combine($tables, array_map(fn (string $table) => DB::table($table)->count(), $tables));
        $counts['timelines'] = DB::table(Schema::hasTable('scopes') ? 'scopes' : 'buildings')->count();
        $counts['actual_dates'] = DB::table('activities')->whereNotNull('actual_date')->count();

        return $counts;
    }

    private function check(bool $condition, string $problem): void
    {
        if (! $condition) {
            throw new RuntimeException("Projects and buildings migration stopped: {$problem}.");
        }
    }
};
