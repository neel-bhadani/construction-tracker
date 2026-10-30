<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sub-activities: the steps inside one activity, each 0, 25, 50, 75 or 100% done. They drive the
 * activity's own progress bar and, once all are 100%, its actual date. Nothing existing changes.
 *
 * work_id is the activity's Work ID and the sub-activity's number within it, "SV-012.3", from the
 * activity's next_sub_number: given once, never reused. position is the order they are shown in.
 * A deleted sub-activity is soft-deleted; its activity's own deletion takes its rows with it, and
 * the audit trail (sub_activity_revisions) outlives both, as activity_revisions does.
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
            $table->unsignedInteger('next_sub_number')->default(0)->after('work_id');
        });

        Schema::create('sub_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('work_number');
            $table->string('work_id', 32);
            $table->unsignedInteger('position');
            $table->string('name', 255);
            $table->date('planned_date')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            // required at 100%, cleared below it; the same rules as an actual date
            $table->date('done_date')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['activity_id', 'work_number']);
            $table->index(['activity_id', 'position']);
        });
        DB::statement('ALTER TABLE sub_activities ADD CONSTRAINT sub_activities_progress_check CHECK (progress IN (0, 25, 50, 75, 100))');

        Schema::create('sub_activity_revisions', function (Blueprint $table) {
            $table->id();
            // nulled when the row is hard-deleted with its activity; the snapshot keeps the id
            $table->foreignId('sub_activity_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('sub_activity_snapshot_id');
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('field', 40);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['sub_activity_snapshot_id', 'created_at']);
        });

        $after = $this->counts();
        if ($before !== $after) {
            throw new RuntimeException('Sub-activities migration stopped: existing rows changed: '.json_encode(compact('before', 'after')));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sub_activity_revisions');
        Schema::dropIfExists('sub_activities');
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn('next_sub_number');
        });
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $tables = ['projects', 'buildings', 'activities', 'activity_revisions'];
        $counts = array_combine($tables, array_map(fn (string $table) => DB::table($table)->count(), $tables));
        $counts['actual_dates'] = DB::table('activities')->whereNotNull('actual_date')->count();

        return $counts;
    }
};
