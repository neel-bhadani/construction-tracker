<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Work IDs and the project's Common activities.
 *
 * - projects.code: short, from the name's initials ("Shaligram Vrundavan" → SV), unique among one
 *   owner's live projects; next_activity_number is the project's counter, so a number is never reused.
 * - activities.work_number / work_id ("SV-001"), numbered per project: common activities first, then
 *   the live buildings in their order, then deleted buildings; each in list order.
 * - Each project's common_work building is renamed "Common activities", keeping its id and data.
 *   A live project without one gets an empty one. At most one live one per project from now on.
 *
 * The rename and the added buildings are written to building_revisions with MARK as their note, in
 * the owner's name, which is how down() finds exactly what it has to undo. Every data step checks
 * its counts and aborts if anything does not match.
 */
return new class extends Migration
{
    private const NAME = 'Common activities';

    private const MARK = 'Work ID migration';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->checkBeforehand();
        $before = $this->counts();

        // 1. projects: a code and a counter
        Schema::table('projects', function (Blueprint $table) {
            $table->string('code', 8)->nullable()->after('name');
            $table->unsignedInteger('next_activity_number')->default(0)->after('code');
        });
        $this->fillCodes();
        Schema::table('projects', function (Blueprint $table) {
            $table->string('code', 8)->nullable(false)->change();
            // NULL once deleted, as active_name: a deleted project's code is free again
            $table->string('active_code', 8)->nullable()->storedAs('IF(deleted_at IS NULL, code, NULL)');
            $table->unique(['owner_id', 'active_code']);
        });

        // 2. Common activities: renamed, or added where a live project has none
        $added = $this->commonActivities();
        Schema::table('buildings', function (Blueprint $table) {
            // 1 for the live common timeline, else NULL; MySQL lets NULLs share a unique index.
            // Not project_id itself: a generated column cannot read a column with a cascading foreign key.
            $table->boolean('live_common')->nullable()->storedAs("IF(type = 'common_work' AND deleted_at IS NULL, 1, NULL)");
            $table->unique(['project_id', 'live_common']);
        });

        // 3. activities: their Work ID
        Schema::table('activities', function (Blueprint $table) {
            $table->unsignedInteger('work_number')->nullable()->after('building_id');
            $table->string('work_id', 20)->nullable()->after('work_number');
        });
        $this->fillWorkIds();
        Schema::table('activities', function (Blueprint $table) {
            $table->unsignedInteger('work_number')->nullable(false)->change();
            $table->string('work_id', 20)->nullable(false)->change();
        });

        $after = $this->counts();
        $before['buildings'] += $added;
        $before['building_revisions'] += $added + DB::table('building_revisions')->where('note', self::MARK)->where('field', 'name')->count();
        $this->check($before === $after, 'row counts changed: '.json_encode(compact('before', 'after')));
    }

    /**
     * Reverse the migrations. The names and the added buildings come back from the MARK revisions;
     * an added building that has gained activities since is not deleted, and the rollback stops.
     */
    public function down(): void
    {
        $added = DB::table('building_revisions')->where('note', self::MARK)->where('field', 'created')->pluck('building_id');
        $this->check(! DB::table('activities')->whereIn('building_id', $added)->exists(),
            'a Common activities building added by the migration has activities now; move or delete them first');

        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn(['work_number', 'work_id']);
        });

        Schema::table('buildings', function (Blueprint $table) {
            $table->dropUnique(['project_id', 'live_common']);
            $table->dropColumn('live_common');
        });
        foreach (DB::table('building_revisions')->where('note', self::MARK)->where('field', 'name')->get() as $renamed) {
            DB::table('buildings')->where('id', $renamed->building_id)->update(['name' => $renamed->old_value]);
        }
        DB::table('building_revisions')->whereIn('building_id', $added)->delete();
        DB::table('buildings')->whereIn('id', $added)->delete();
        DB::table('building_revisions')->where('note', self::MARK)->delete();

        // owner_id's foreign key keeps the (owner_id, active_name) index, so this one can go
        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['owner_id', 'active_code']);
            $table->dropColumn(['active_code', 'code', 'next_activity_number']);
        });
    }

    /** What would make the rename or the new index fail, refused before any DDL. */
    private function checkBeforehand(): void
    {
        $twice = DB::table('buildings')->where('type', 'common_work')->whereNull('deleted_at')
            ->groupBy('project_id')->havingRaw('COUNT(*) > 1')->pluck('project_id')->all();
        $this->check($twice === [], 'these projects have more than one common timeline: '.implode(', ', $twice));

        $taken = DB::table('buildings')->where('name', self::NAME)->where('type', '!=', 'common_work')->whereNull('deleted_at')->pluck('project_id')->all();
        $this->check($taken === [], 'these projects have a building already named ['.self::NAME.']: '.implode(', ', $taken));
    }

    /** Live projects first, so they get the plain code; a clash gets a number: SV, SV2, SV3. */
    private function fillCodes(): void
    {
        $projects = DB::table('projects')->orderByRaw('deleted_at IS NOT NULL')->orderBy('id')->get(['id', 'owner_id', 'name', 'deleted_at']);
        $taken = [];

        foreach ($projects as $project) {
            $base = $this->initials($project->name);
            $code = $base;
            if ($project->deleted_at === null) {
                for ($n = 2; isset($taken[$project->owner_id][$code]); $n++) {
                    $code = substr($base, 0, 8 - strlen((string) $n)).$n;
                }
                $taken[$project->owner_id][$code] = true;
            }
            DB::table('projects')->where('id', $project->id)->update(['code' => $code]);
        }
    }

    /** The same rule as App\Models\Project::initials(), copied so the migration never changes with the app. */
    private function initials(string $name): string
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $name, $words);
        $initials = implode('', array_map(fn (string $word) => mb_substr($word, 0, 1), $words[0]));
        $code = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($initials)), 0, 8);

        return $code === '' ? 'P' : $code;
    }

    /**
     * @return int how many buildings were added
     */
    private function commonActivities(): int
    {
        $now = now();
        $owners = DB::table('projects')->pluck('owner_id', 'id');

        foreach (DB::table('buildings')->where('type', 'common_work')->where('name', '!=', self::NAME)->get(['id', 'project_id', 'name']) as $common) {
            DB::table('buildings')->where('id', $common->id)->update(['name' => self::NAME, 'updated_at' => $now]);
            DB::table('building_revisions')->insert(['building_id' => $common->id, 'user_id' => $owners[$common->project_id],
                'field' => 'name', 'old_value' => $common->name, 'new_value' => self::NAME, 'note' => self::MARK, 'created_at' => $now]);
        }

        $without = DB::table('projects')->whereNull('deleted_at')
            ->whereNotExists(fn ($query) => $query->from('buildings')->whereColumn('buildings.project_id', 'projects.id')
                ->where('type', 'common_work')->whereNull('buildings.deleted_at'))
            ->orderBy('id')->get(['id', 'owner_id', 'planned_start_date']);

        foreach ($without as $project) {
            $id = DB::table('buildings')->insertGetId(['project_id' => $project->id, 'name' => self::NAME, 'type' => 'common_work',
                'planned_start_date' => $project->planned_start_date, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('building_revisions')->insert(['building_id' => $id, 'user_id' => $project->owner_id,
                'field' => 'created', 'new_value' => self::NAME, 'note' => self::MARK, 'created_at' => $now]);
        }

        return $without->count();
    }

    /** Numbered per project: common activities, live buildings in order, then deleted buildings. */
    private function fillWorkIds(): void
    {
        foreach (DB::table('projects')->orderBy('id')->get(['id', 'code']) as $project) {
            $buildings = DB::table('buildings')->where('project_id', $project->id)
                ->orderByRaw('deleted_at IS NOT NULL')
                ->orderByRaw("type != 'common_work'")
                ->orderBy('sort_order')->orderBy('id')
                ->pluck('id');

            $number = 0;
            foreach ($buildings as $building) {
                $ids = DB::table('activities')->where('building_id', $building)->orderBy('sort_order')->orderBy('id')->pluck('id')->all();
                if ($ids === []) {
                    continue;
                }

                $numbers = [];
                foreach ($ids as $id) {
                    $numbers[$id] = ++$number;
                }
                $case = fn (callable $value) => 'CASE id '.implode(' ', array_map(fn ($id) => 'WHEN '.(int) $id.' THEN '.$value($numbers[$id]), $ids)).' END';
                DB::update('UPDATE activities SET work_number = '.$case(fn (int $n) => $n)
                    .', work_id = '.$case(fn (int $n) => DB::getPdo()->quote($project->code.'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT)))
                    .' WHERE id IN ('.implode(', ', array_map('intval', $ids)).')');
            }

            DB::table('projects')->where('id', $project->id)->update(['next_activity_number' => $number]);
        }

        $this->check(! DB::table('activities')->whereNull('work_id')->exists(), 'an activity has no Work ID');
        $duplicates = DB::table('activities')->join('buildings', 'buildings.id', '=', 'activities.building_id')
            ->select('buildings.project_id', 'activities.work_number')
            ->groupBy('buildings.project_id', 'activities.work_number')->havingRaw('COUNT(*) > 1')->get()->count();
        $this->check($duplicates === 0, 'a Work ID is used twice in a project');
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $tables = ['users', 'projects', 'buildings', 'activities', 'activity_revisions', 'building_revisions', 'project_revisions'];
        $counts = array_combine($tables, array_map(fn (string $table) => DB::table($table)->count(), $tables));
        $counts['actual_dates'] = DB::table('activities')->whereNotNull('actual_date')->count();

        return $counts;
    }

    private function check(bool $condition, string $problem): void
    {
        if (! $condition) {
            throw new RuntimeException("Work ID migration stopped: {$problem}.");
        }
    }
};
