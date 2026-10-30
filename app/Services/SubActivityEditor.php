<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ActivityRevision;
use App\Models\SubActivity;
use App\Models\SubActivityRevision;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Every change to sub-activities: one transaction each, the activity row locked, an audit row per
 * changed field. After each change the activity's own done state is re-derived (sync()):
 *
 * - all its sub-activities at 100%: done, actual date = the latest done date;
 * - any below 100%: not done, actual date cleared.
 *
 * Deleting the last one leaves the activity as it is: it keeps its state and date and goes back
 * to being marked done by hand. No change here moves a planned date.
 */
class SubActivityEditor
{
    /** What the sub-activity dialog saves. */
    private const FIELDS = ['name', 'planned_date', 'progress', 'done_date', 'note'];

    /**
     * The first sub-activity of an activity that is already done starts at 100%, done on the
     * activity's actual date, unless the request says otherwise; any other starts at 0%.
     *
     * @return array{progress: int, done_date: ?string}
     */
    public static function defaults(Activity $activity): array
    {
        $first = ! $activity->subActivities()->exists();

        return $first && $activity->actual_date !== null
            ? ['progress' => 100, 'done_date' => $activity->actual_date->toDateString()]
            : ['progress' => 0, 'done_date' => null];
    }

    /**
     * @param  array<string, mixed>  $data  FIELDS; progress and done_date default to defaults()
     */
    public function create(Activity $activity, array $data, User $user): SubActivity
    {
        return DB::transaction(function () use ($activity, $data, $user) {
            $activity = Activity::lockForUpdate()->findOrFail($activity->id);
            if ($activity->is_break) {
                throw new LogicException('A break has no sub-activities.');
            }

            $data = $this->normalise([...self::defaults($activity), ...$data]);
            $number = $activity->next_sub_number + 1;
            $activity->forceFill(['next_sub_number' => $number])->save();

            $sub = new SubActivity($data);
            $sub->forceFill([
                'activity_id' => $activity->id,
                'work_number' => $number,
                'work_id' => "{$activity->work_id}.{$number}",
                'position' => (int) SubActivity::where('activity_id', $activity->id)->max('position') + 1,
            ])->save();

            $this->revision($sub, $user, 'created', null, $sub->name);
            foreach (array_diff(self::FIELDS, ['name']) as $field) {
                $value = $this->value($sub, $field);
                if (! in_array($value, [null, '0'], true)) {
                    $this->revision($sub, $user, $field, null, $value);
                }
            }

            $this->sync($activity, $user);

            return $sub->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data  FIELDS
     */
    public function update(SubActivity $sub, array $data, User $user): SubActivity
    {
        return DB::transaction(function () use ($sub, $data, $user) {
            $activity = Activity::lockForUpdate()->findOrFail($sub->activity_id);
            $sub = SubActivity::lockForUpdate()->findOrFail($sub->id);

            $before = array_combine(self::FIELDS, array_map(fn (string $field) => $this->value($sub, $field), self::FIELDS));
            $sub->fill($this->normalise([...$before, ...array_intersect_key($data, array_flip(self::FIELDS))]))->save();

            foreach (self::FIELDS as $field) {
                $after = $this->value($sub, $field);
                if ($after !== $before[$field]) {
                    $this->revision($sub, $user, $field, $before[$field], $after);
                }
            }

            $this->sync($activity, $user);

            return $sub->refresh();
        });
    }

    /** A soft delete. The last one leaves the activity's state and date as they are. */
    public function delete(SubActivity $sub, User $user): void
    {
        DB::transaction(function () use ($sub, $user) {
            $activity = Activity::lockForUpdate()->findOrFail($sub->activity_id);
            $sub = SubActivity::lockForUpdate()->findOrFail($sub->id);

            $this->revision($sub, $user, 'deleted', $sub->name, null);
            $sub->delete();

            $this->sync($activity, $user);
        });
    }

    /**
     * Positions 1..n in the given order. Like an activity's position, not an edit to the rows: no
     * audit, no updated_at. Nothing is renumbered: Work IDs stay as they were given.
     *
     * @param  list<int>  $ids  every live sub-activity of the activity, once each
     */
    public function reorder(Activity $activity, array $ids): void
    {
        DB::transaction(function () use ($activity, $ids) {
            Activity::lockForUpdate()->findOrFail($activity->id);
            $live = SubActivity::where('activity_id', $activity->id)->pluck('id')->sort()->values()->all();
            $given = collect($ids)->map(fn ($id) => (int) $id);

            if ($given->sort()->values()->all() !== $live) {
                throw new LogicException('The new order must name every sub-activity of the activity once.');
            }

            foreach ($given->values() as $index => $id) {
                SubActivity::whereKey($id)->toBase()->update(['position' => $index + 1]);
            }
        });
    }

    /**
     * The activity's actual date as its sub-activities say: the latest done date once all are at
     * 100%, none before. Without sub-activities it is left alone. A change is audited on the
     * activity, as a recorded actual date is.
     */
    private function sync(Activity $activity, User $user): void
    {
        $subs = SubActivity::where('activity_id', $activity->id)->get(['progress', 'done_date']);
        if ($subs->isEmpty()) {
            return;
        }

        $done = $subs->every(fn (SubActivity $sub) => $sub->progress === 100);
        $actual = $done ? $subs->max(fn (SubActivity $sub) => $sub->done_date->toDateString()) : null;
        $before = $activity->actual_date?->toDateString();

        if ($actual !== $before) {
            $activity->forceFill(['actual_date' => $actual])->save();
            ActivityRevision::create([
                'activity_id' => $activity->id,
                'activity_snapshot_id' => $activity->id,
                'user_id' => $user->id,
                'field' => 'actual_date',
                'old_value' => $before,
                'new_value' => $actual,
            ]);
        }
    }

    /**
     * A done date belongs to 100% only; blank text is no text.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        $data['progress'] = (int) ($data['progress'] ?? 0);
        if ($data['progress'] !== 100) {
            $data['done_date'] = null;
        }
        foreach (['note', 'planned_date', 'done_date'] as $field) {
            if (array_key_exists($field, $data) && trim((string) $data[$field]) === '') {
                $data[$field] = null;
            }
        }

        return $data;
    }

    /** A field as it is written to the audit trail. */
    private function value(SubActivity $sub, string $field): ?string
    {
        $value = $sub->getAttribute($field);

        return match (true) {
            $value === null => null,
            $value instanceof CarbonInterface => $value->toDateString(),
            default => (string) $value,
        };
    }

    private function revision(SubActivity $sub, User $user, string $field, ?string $old, ?string $new): void
    {
        SubActivityRevision::create([
            'sub_activity_id' => $sub->id,
            'sub_activity_snapshot_id' => $sub->id,
            'user_id' => $user->id,
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
        ]);
    }
}
