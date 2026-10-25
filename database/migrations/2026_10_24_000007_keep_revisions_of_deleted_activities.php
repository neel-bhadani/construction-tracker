<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An audit trail that vanishes with the row fails exactly when someone needs it. Revisions now
 * outlive their activity: activity_id is nulled on delete, and the deletion itself is recorded
 * as a "deleted" revision carrying the description.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('activity_revisions', function (Blueprint $table) {
            $table->dropForeign(['activity_id']);
            $table->unsignedBigInteger('activity_id')->nullable()->change();
            $table->foreign('activity_id')->references('id')->on('activities')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_revisions', function (Blueprint $table) {
            $table->dropForeign(['activity_id']);
        });

        DB::table('activity_revisions')->whereNull('activity_id')->delete();

        Schema::table('activity_revisions', function (Blueprint $table) {
            $table->unsignedBigInteger('activity_id')->nullable(false)->change();
            $table->foreign('activity_id')->references('id')->on('activities')->cascadeOnDelete();
        });
    }
};
