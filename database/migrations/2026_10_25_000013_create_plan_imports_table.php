<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per project imported with `php artisan timeline:import-json`: who, which file (by
 * content hash, so the same file cannot be imported twice for one owner) and what it created.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('plan_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            // kept when the project is deleted; the name says what it was
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('project_name', 150);
            $table->string('file', 255);
            $table->char('sha256', 64);
            $table->json('counts');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['owner_id', 'sha256']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_imports');
    }
};
