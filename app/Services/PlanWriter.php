<?php

namespace App\Services;

use App\Models\ActivityRevision;
use App\Models\Building;
use App\Models\SubActivityRevision;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes a whole plan into a building in one go: the workbook import, a copied plan, an Excel
 * upload. Work IDs are given in the order of $rows. Rows are inserted with a placeholder planned_date, wired to their predecessors, and
 * recalculated — all inside the caller's transaction. Planned dates are never copied; they are
 * always computed from this building's own start.
 */
class PlanWriter
{
    public function __construct(
        private readonly ScheduleRecalculator $recalculator,
        private readonly WorkIds $workIds,
    ) {}

    /**
     * @param  array<int|string, array{description: string, predecessor: int|string|null, offset_days: int, date_adjustment_days: int, adjustment_reason: ?string, is_break: bool, sort_order: int, actual_date?: ?string, notes?: ?string, sub_activities?: list<array{name: string, planned_date: ?string}>}>  $rows  keyed by any id the predecessor keys refer to; sub-activities start at 0%
     * @param  ?User  $user  the person creating it; each activity then gets a `created` revision naming $origin
     * @return array<int|string, int> new activity id for each row key
     */
    public function write(Building $building, array $rows, ?User $user = null, ?string $origin = null): array
    {
        return DB::transaction(function () use ($building, $rows, $user, $origin) {
            $ids = [];
            $workIds = $rows === [] ? [] : $this->workIds->take($building->project_id, count($rows));
            $number = array_key_first($workIds);

            foreach ($rows as $key => $row) {
                $ids[$key] = DB::table('activities')->insertGetId([
                    'building_id' => $building->id,
                    'work_number' => $number,
                    'work_id' => $workIds[$number++],
                    'description' => $row['description'],
                    'offset_days' => $row['offset_days'],
                    'date_adjustment_days' => $row['date_adjustment_days'],
                    'adjustment_reason' => $row['adjustment_reason'],
                    'is_break' => $row['is_break'],
                    'planned_date' => $building->planned_start_date->toDateString(),
                    'actual_date' => $row['actual_date'] ?? null,
                    'sort_order' => $row['sort_order'],
                    'notes' => $row['notes'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($rows as $key => $row) {
                if ($row['predecessor'] === null) {
                    continue;
                }

                $predecessorId = $ids[$row['predecessor']]
                    ?? throw new RuntimeException("[{$row['description']}] follows an activity that is not part of this plan.");

                DB::table('activities')->where('id', $ids[$key])->update(['predecessor_id' => $predecessorId]);
            }

            foreach ($rows as $key => $row) {
                $this->writeSubActivities($ids[$key], $row['sub_activities'] ?? [], $user, $origin);
            }

            $this->recalculator->recalculate($building);

            if ($user !== null) {
                foreach ($rows as $key => $row) {
                    ActivityRevision::create([
                        'activity_id' => $ids[$key],
                        'activity_snapshot_id' => $ids[$key],
                        'user_id' => $user->id,
                        'field' => 'created',
                        'new_value' => $origin === null ? $row['description'] : "{$row['description']} ({$origin})",
                    ]);
                }
            }

            return $ids;
        });
    }

    /**
     * A copied activity's sub-activities, in their order, numbered .1, .2… under its new Work ID.
     *
     * @param  list<array{name: string, planned_date: ?string}>  $subs
     */
    private function writeSubActivities(int $activityId, array $subs, ?User $user, ?string $origin): void
    {
        if ($subs === []) {
            return;
        }

        $workId = DB::table('activities')->where('id', $activityId)->value('work_id');
        foreach (array_values($subs) as $index => $sub) {
            $id = DB::table('sub_activities')->insertGetId([
                'activity_id' => $activityId,
                'work_number' => $index + 1,
                'work_id' => "{$workId}.".($index + 1),
                'position' => $index + 1,
                'name' => $sub['name'],
                'planned_date' => $sub['planned_date'],
                'progress' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($user !== null) {
                SubActivityRevision::create(['sub_activity_id' => $id, 'sub_activity_snapshot_id' => $id, 'user_id' => $user->id,
                    'field' => 'created', 'new_value' => $origin === null ? $sub['name'] : "{$sub['name']} ({$origin})"]);
            }
        }
        DB::table('activities')->where('id', $activityId)->update(['next_sub_number' => count($subs)]);
    }
}
