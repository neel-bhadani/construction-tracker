<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * activity_id is nulled when its activity is deleted, which leaves the revisions of two deleted
 * activities indistinguishable. activity_snapshot_id keeps the id for good: written on every
 * revision, never nulled, no foreign key. activity_id stays for joins to live activities.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('activity_revisions', function (Blueprint $table) {
            $table->unsignedBigInteger('activity_snapshot_id')->nullable()->after('activity_id');
        });

        DB::table('activity_revisions')->update(['activity_snapshot_id' => DB::raw('activity_id')]);

        // a revision whose activity is already deleted has lost its id; refuse rather than invent one
        if (DB::table('activity_revisions')->whereNull('activity_snapshot_id')->exists()) {
            throw new RuntimeException('Some activity_revisions rows have no activity_id to snapshot; resolve them by hand.');
        }

        Schema::table('activity_revisions', function (Blueprint $table) {
            $table->unsignedBigInteger('activity_snapshot_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_revisions', function (Blueprint $table) {
            $table->dropColumn('activity_snapshot_id');
        });
    }
};
