<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Floors of a building, and which floor each activity is on. Floors are optional: an activity
 * with no floor is "Whole building / common" work (masonry, handover, breaks), and a building with
 * no floors at all is exactly what it was. Nothing existing is changed: every floor_id starts null.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $before = $this->counts();

        Schema::create('floors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('building_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            // for sorting: ground 0, basements negative
            $table->smallInteger('level');
            $table->timestamps();
            $table->softDeletes();
            // NULL once deleted, so a deleted floor's name is free again; as on buildings
            $table->string('active_name', 50)->nullable()->storedAs('IF(deleted_at IS NULL, name, NULL)');

            $table->unique(['building_id', 'active_name']);
            $table->index(['building_id', 'level']);
        });

        Schema::table('activities', function (Blueprint $table) {
            // deleting a floor moves its activities to the whole building first (FloorEditor); this is the backstop
            $table->foreignId('floor_id')->nullable()->after('building_id')->constrained()->nullOnDelete();
        });

        $after = $this->counts();
        if ($before !== $after || DB::table('activities')->whereNotNull('floor_id')->exists()) {
            throw new RuntimeException('Floors migration stopped: existing rows changed: '.json_encode(compact('before', 'after')));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('floor_id');
        });
        Schema::dropIfExists('floors');
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $tables = ['buildings', 'activities', 'activity_revisions', 'building_revisions'];

        return array_combine($tables, array_map(fn (string $table) => DB::table($table)->count(), $tables));
    }
};
