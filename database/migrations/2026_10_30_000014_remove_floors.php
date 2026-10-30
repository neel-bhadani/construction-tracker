<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Floors go: every activity is simply the building's. The floor each activity was on is dropped
 * with the column; the audit trail keeps any `floor_id` revisions as history. Nothing else may
 * change, which the counts check.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $before = $this->counts();

        Schema::table('activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('floor_id');
        });
        Schema::dropIfExists('floors');

        $after = $this->counts();
        if ($before !== $after) {
            throw new RuntimeException('Remove floors migration stopped: rows changed: '.json_encode(compact('before', 'after')));
        }
    }

    /**
     * Reverse the migrations. The table and column come back empty: which floor each activity was
     * on is not kept anywhere but a backup.
     */
    public function down(): void
    {
        Schema::create('floors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->smallInteger('level');
            $table->timestamps();
            $table->softDeletes();
            $table->string('active_name', 50)->nullable()->storedAs('IF(deleted_at IS NULL, name, NULL)');

            $table->unique(['building_id', 'active_name']);
            $table->index(['building_id', 'level']);
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->foreignId('floor_id')->nullable()->after('building_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $tables = ['projects', 'buildings', 'activities', 'activity_revisions', 'building_revisions'];
        $counts = array_combine($tables, array_map(fn (string $table) => DB::table($table)->count(), $tables));
        $counts['actual_dates'] = DB::table('activities')->whereNotNull('actual_date')->count();

        return $counts;
    }
};
