<?php

namespace App\Services;

use App\Models\ActivityRevision;
use App\Models\Building;
use App\Models\BuildingRevision;
use App\Models\Floor;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Every change to a building's floors: one transaction each, audited on the building. A floor
 * moves no planned date, so nothing here recalculates.
 */
class FloorEditor
{
    public function add(Building $building, string $name, int $level, User $user): Floor
    {
        return DB::transaction(function () use ($building, $name, $level, $user) {
            $floor = Floor::create(['building_id' => $building->id, 'name' => $name, 'level' => $level]);
            $this->revision($building, $user, 'floor_added', null, $floor->name);

            return $floor;
        });
    }

    /**
     * The quick option: basements, the ground floor and 1st to $upTo floor, named the usual way.
     * A floor whose name the building already has is left as it is, so running it twice adds
     * nothing.
     *
     * @return list<Floor> the floors added
     */
    public function addRange(Building $building, int $upTo, int $basements, User $user): array
    {
        return DB::transaction(function () use ($building, $upTo, $basements, $user) {
            $existing = Floor::where('building_id', $building->id)->lockForUpdate()->pluck('name')->map(fn ($name) => mb_strtolower($name))->all();
            $added = [];

            foreach (range(-$basements, $upTo) as $level) {
                $name = Floor::nameForLevel($level);
                if (! in_array(mb_strtolower($name), $existing, true)) {
                    $added[] = $this->add($building, $name, $level, $user);
                }
            }

            return $added;
        });
    }

    public function update(Floor $floor, string $name, int $level, User $user): Floor
    {
        return DB::transaction(function () use ($floor, $name, $level, $user) {
            $floor = Floor::lockForUpdate()->findOrFail($floor->id);
            $old = $floor->name;
            $floor->fill(['name' => $name, 'level' => $level])->save();

            if ($old !== $floor->name) {
                $this->revision($floor->building, $user, 'floor_renamed', $old, $floor->name);
            }

            return $floor;
        });
    }

    /**
     * Its activities move to the whole building, each with a revision of its own, then the floor
     * is soft-deleted. Their dates and actuals do not change.
     *
     * @return int how many activities moved
     */
    public function delete(Floor $floor, User $user): int
    {
        return DB::transaction(function () use ($floor, $user) {
            $floor = Floor::lockForUpdate()->findOrFail($floor->id);
            $activities = $floor->activities()->lockForUpdate()->orderBy('sort_order')->orderBy('id')->get(['id']);

            foreach ($activities as $activity) {
                ActivityRevision::create([
                    'activity_id' => $activity->id,
                    'activity_snapshot_id' => $activity->id,
                    'user_id' => $user->id,
                    'field' => 'floor_id',
                    'old_value' => (string) $floor->id,
                    'new_value' => null,
                ]);
            }
            // a query, not the models: like the recalculator, updated_at keeps recording the last edit
            DB::table('activities')->where('floor_id', $floor->id)->update(['floor_id' => null]);

            $this->revision($floor->building, $user, 'floor_deleted', $floor->name, null,
                match ($activities->count()) {
                    0 => null,
                    1 => '1 activity moved to Whole building',
                    default => "{$activities->count()} activities moved to Whole building",
                });
            $floor->delete();

            return $activities->count();
        });
    }

    private function revision(Building $building, User $user, string $field, ?string $old, ?string $new, ?string $note = null): void
    {
        BuildingRevision::create([
            'building_id' => $building->id, 'user_id' => $user->id, 'field' => $field,
            'old_value' => $old, 'new_value' => $new, 'note' => $note,
        ]);
    }
}
