<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scope_id')->constrained()->cascadeOnDelete();
            $table->string('description', 255);
            // Restrict, not cascade or null: deleting an activity others depend on must be blocked.
            $table->foreignId('predecessor_id')->nullable()->constrained('activities')->restrictOnDelete();
            $table->unsignedSmallInteger('offset_days')->default(0);
            $table->smallInteger('date_adjustment_days')->default(0);
            $table->string('adjustment_reason', 255)->nullable();
            $table->boolean('is_break')->default(false);
            // Derived, stored. Written only by ScheduleRecalculator.
            $table->unsignedInteger('planned_day')->default(0);
            $table->date('planned_date');
            $table->date('actual_date')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['scope_id', 'sort_order']);
            $table->index(['scope_id', 'actual_date']);
            $table->index('predecessor_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activities');
    }
};
