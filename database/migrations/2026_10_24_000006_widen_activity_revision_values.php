<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * activities.notes is text, and a changed note is audited like any other field. A varchar(255)
 * revision column would refuse any note longer than that, so an overwritten note could not be
 * traced.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('activity_revisions', function (Blueprint $table) {
            $table->text('old_value')->nullable()->change();
            $table->text('new_value')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_revisions', function (Blueprint $table) {
            $table->string('old_value', 255)->nullable()->change();
            $table->string('new_value', 255)->nullable()->change();
        });
    }
};
