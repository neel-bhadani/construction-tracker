<?php

namespace App\Services;

use App\Models\ActivityRevision;
use App\Models\Building;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Writes a whole plan into a building in one go: the workbook import, a copied plan, an Excel
 * upload. Rows are inserted with a placeholder planned_date, wired to their predecessors, and
 * recalculated — all inside the caller's transaction. Planned dates are never copied; they are
 * always computed from this building's own start.
 */
class PlanWriter
{
    public function __construct(private readonly ScheduleRecalculator $recalculator) {}

    /**
     * @param  array<int|string, array{description: string, predecessor: int|string|null, offset_days: int, date_adjustment_days: int, adjustment_reason: ?string, is_break: bool, sort_order: int, actual_date?: ?string, floor_id?: ?int}>  $rows  keyed by any id the predecessor keys refer to; floor_id is a floor of $building
     * @param  ?User  $user  the person creating it; each activity then gets a `created` revision naming $origin
     * @return array<int|string, int> new activity id for each row key
     */
    public function write(Building $building, array $rows, ?User $user = null, ?string $origin = null): array
    {
        return DB::transaction(function () use ($building, $rows, $user, $origin) {
            $ids = [];

            foreach ($rows as $key => $row) {
                $ids[$key] = DB::table('activities')->insertGetId([
                    'building_id' => $building->id,
                    'floor_id' => $row['floor_id'] ?? null,
                    'description' => $row['description'],
                    'offset_days' => $row['offset_days'],
                    'date_adjustment_days' => $row['date_adjustment_days'],
                    'adjustment_reason' => $row['adjustment_reason'],
                    'is_break' => $row['is_break'],
                    'planned_date' => $building->planned_start_date->toDateString(),
                    'actual_date' => $row['actual_date'] ?? null,
                    'sort_order' => $row['sort_order'],
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
}
