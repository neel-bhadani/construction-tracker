<?php

namespace App\Services;

use App\Data\SchedulePreview;
use App\Exceptions\ActivityHasDependantsException;
use App\Exceptions\InvalidScheduleException;
use App\Models\Activity;
use App\Models\ActivityRevision;
use App\Models\Building;
use App\Models\BuildingRevision;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every user change to the schedule goes through here: one transaction each, an audit row per
 * changed field, and a recalculation when the change can move planned dates. The recalculation
 * cascade itself is never audited — one duration change must not produce fifty rows.
 */
class ScheduleEditor
{
    /** The plan's fields; changing any of the first three moves planned dates. */
    public const PLAN_FIELDS = ['predecessor_id', 'offset_days', 'date_adjustment_days', 'adjustment_reason', 'description', 'is_break', 'notes'];

    private const CHAIN_FIELDS = ['predecessor_id', 'offset_days', 'date_adjustment_days'];

    /** What happened on site; neither moves a planned date. */
    private const ACTUAL_FIELDS = ['actual_date', 'notes'];

    /** What the activity dialog saves: the plan and the actual date. */
    private const DIALOG_FIELDS = [...self::PLAN_FIELDS, 'actual_date'];

    public function __construct(
        private readonly ScheduleRecalculator $recalculator,
        private readonly WorkIds $workIds,
    ) {}

    /**
     * Run the recalculator's own computation on the graph as it would be after the change, in
     * memory. Nothing is written. A change that would create a cycle throws the recalculator's
     * ScheduleCycleException, naming the activities in it.
     *
     * A new activity inserted below $afterId with $push takes over the row below: see pushedRow().
     *
     * @param  array{predecessor_id: ?int, offset_days: int, date_adjustment_days: int}  $chain
     *
     * @throws InvalidScheduleException
     */
    public function preview(Building $building, array $chain, ?Activity $activity = null, ?int $afterId = null, bool $push = false): SchedulePreview
    {
        $startDate = $building->planned_start_date;
        $stored = Activity::query()
            ->where('building_id', $building->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get([...ScheduleRecalculator::COLUMNS, 'planned_date'])
            ->keyBy('id');

        // the proposed activity: an unsaved copy, or a new one under id 0, which no row ever has
        $proposed = $activity === null
            ? (new Activity)->forceFill(['id' => 0, 'description' => 'New activity', 'sort_order' => PHP_INT_MAX])
            : clone $stored[$activity->id];
        $proposed->forceFill($chain);

        $graph = $stored->map(fn (Activity $row) => clone $row)->put($proposed->id, $proposed);
        $pushed = $activity === null && $push && $afterId !== null ? $this->pushedRow($stored, $afterId) : null;
        if ($pushed !== null) {
            $graph[$pushed->id]->predecessor_id = $proposed->id;
        }
        $computed = $this->recalculator->compute($graph, $startDate);

        $changed = $stored->except($proposed->id)->filter(
            fn (Activity $row) => $row->planned_date->toDateString() !== $computed[$row->id]['planned_date'],
        );

        return new SchedulePreview(
            plannedDate: $computed[$proposed->id]['planned_date'],
            changedCount: $changed->count(),
            finishBefore: $stored->max(fn (Activity $row) => $row->planned_date->toDateString()),
            finishAfter: collect($computed)->max('planned_date'),
            changed: $changed->take(SchedulePreview::LISTED)->map(fn (Activity $row) => [
                'id' => $row->id,
                'description' => $row->description,
                'before' => $row->planned_date->toDateString(),
                'after' => $computed[$row->id]['planned_date'],
            ])->values()->all(),
            pushes: $pushed?->description,
        );
    }

    /**
     * "Push the activities after it later": the row directly below $afterId in the list, if that
     * row comes after $afterId. It is relinked to come after the new activity, so it and everything
     * chained to it move by the new activity's days. Any other row is left alone.
     *
     * @param  Collection<int, Activity>  $rows  keyed by id, in row order
     */
    private function pushedRow(Collection $rows, int $afterId): ?Activity
    {
        $ids = $rows->keys()->all();
        $below = $ids[array_search($afterId, $ids, true) + 1] ?? null;

        return $below !== null && $rows[$below]->predecessor_id === $afterId ? $rows[$below] : null;
    }

    /**
     * Never a bare Activity::create(): planned_date cannot be null, so the row is written with a
     * placeholder and recalculated in the same transaction.
     *
     * At the end of the list, or right below $afterId. Inserting renumbers every row's position in
     * the same transaction; with $push the row below is relinked (pushedRow()). Actual dates never
     * change.
     *
     * @param  array<string, mixed>  $data  DIALOG_FIELDS
     */
    public function create(Building $building, array $data, User $user, ?int $afterId = null, bool $push = false): Activity
    {
        return DB::transaction(function () use ($building, $data, $user, $afterId, $push) {
            $rows = Activity::where('building_id', $building->id)->orderBy('sort_order')->orderBy('id')->lockForUpdate()
                ->get(['id', 'description', 'predecessor_id', 'sort_order'])->keyBy('id');

            $activity = new Activity($this->normalise($data));
            $activity->building_id = $building->id;
            $activity->sort_order = $rows->max('sort_order') + 1;
            $workIds = $this->workIds->take($building->project_id);
            $activity->forceFill(['work_number' => array_key_first($workIds), 'work_id' => reset($workIds)]);
            $activity->forceFill(['planned_day' => 0, 'planned_date' => $building->planned_start_date])->save();

            $this->revision($activity, $user, 'created', null, $activity->description);
            foreach (array_diff(self::DIALOG_FIELDS, ['description']) as $field) {
                $value = $this->value($activity, $field);
                if (! in_array($value, [null, '0', 'false'], true)) {
                    $this->revision($activity, $user, $field, null, $value);
                }
            }

            if ($afterId !== null) {
                $this->renumber($rows->keys()->all(), $afterId, $activity->id);

                $pushed = $push ? $this->pushedRow($rows, $afterId) : null;
                if ($pushed !== null) {
                    $pushed = Activity::findOrFail($pushed->id);
                    $this->apply($pushed, ['predecessor_id' => $activity->id], ['predecessor_id'], $user);
                }
            }

            $this->recalculator->recalculate($building);

            return $activity->refresh();
        });
    }

    /**
     * Positions 1..n in row order, with the new activity right after $afterId. One statement, and
     * like the recalculator's it leaves updated_at alone: a position is not an edit to the row.
     *
     * @param  list<int>  $ids  the existing rows, in order
     */
    private function renumber(array $ids, int $afterId, int $newId): void
    {
        $at = array_search($afterId, $ids, true);
        $order = [...array_slice($ids, 0, $at + 1), $newId, ...array_slice($ids, $at + 1)];

        DB::update(
            'UPDATE activities SET sort_order = CASE id '.implode(' ', array_fill(0, count($order), 'WHEN ? THEN ?')).' END WHERE id IN ('.implode(', ', array_fill(0, count($order), '?')).')',
            [...collect($order)->flatMap(fn (int $id, int $index) => [$id, $index + 1]), ...$order],
        );
    }

    /**
     * @param  array<string, mixed>  $data  DIALOG_FIELDS
     */
    public function update(Activity $activity, array $data, User $user): Activity
    {
        return DB::transaction(function () use ($activity, $data, $user) {
            $activity = Activity::lockForUpdate()->findOrFail($activity->id);
            $changed = $this->apply($activity, $this->normalise($data), self::DIALOG_FIELDS, $user);

            if (array_intersect($changed, self::CHAIN_FIELDS) !== []) {
                $this->recalculator->recalculate($activity->building);
            }

            return $activity->refresh();
        });
    }

    /**
     * Recording, changing or clearing an actual date. Actuals never move planned dates, so there
     * is no recalculation.
     */
    public function recordActual(Activity $activity, ?string $actualDate, ?string $notes, User $user): Activity
    {
        return DB::transaction(function () use ($activity, $actualDate, $notes, $user) {
            $activity = Activity::lockForUpdate()->findOrFail($activity->id);
            $this->apply($activity, ['actual_date' => $actualDate, 'notes' => $notes], self::ACTUAL_FIELDS, $user);

            return $activity->refresh();
        });
    }

    /**
     * @throws ActivityHasDependantsException
     */
    public function delete(Activity $activity, User $user): void
    {
        DB::transaction(function () use ($activity, $user) {
            $activity = Activity::lockForUpdate()->findOrFail($activity->id);
            $dependants = $activity->dependants()->orderBy('sort_order')->orderBy('id')->pluck('description')->all();

            if ($dependants !== []) {
                throw new ActivityHasDependantsException($activity->description, $dependants);
            }

            // written first, so the foreign key nulls it with the rest of the trail
            $this->revision($activity, $user, 'deleted', $activity->description, null);
            $building = $activity->building;
            $activity->delete();

            $this->recalculator->recalculate($building);
        });
    }

    /**
     * A building's start dates. The planned start moves every planned date in the building, so it
     * is recalculated; the actual start moves no planned date but drives average pace.
     */
    public function updateBuildingDates(Building $building, string $plannedStart, ?string $actualStart, User $user, ?string $note = null): Building
    {
        return DB::transaction(function () use ($building, $plannedStart, $actualStart, $user, $note) {
            $building = Building::lockForUpdate()->findOrFail($building->id);
            $changed = [];

            foreach (['planned_start_date' => $plannedStart, 'actual_start_date' => $actualStart] as $field => $value) {
                $old = $building->{$field}?->toDateString();
                if ($old !== $value) {
                    $changed[$field] = [$old, $value];
                }
            }

            $building->fill(['planned_start_date' => $plannedStart, 'actual_start_date' => $actualStart])->save();

            foreach ($changed as $field => [$old, $new]) {
                BuildingRevision::create(['building_id' => $building->id, 'user_id' => $user->id, 'field' => $field, 'old_value' => $old, 'new_value' => $new, 'note' => $note]);
            }

            if (isset($changed['planned_start_date'])) {
                $this->recalculator->recalculate($building);
            }

            return $building->refresh();
        });
    }

    /**
     * Fill the given fields, save, and write one revision for each that actually changed.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $fields
     * @return list<string> the fields that changed
     */
    private function apply(Activity $activity, array $data, array $fields, User $user): array
    {
        $before = [];
        foreach ($fields as $field) {
            $before[$field] = $this->value($activity, $field);
        }

        $activity->fill(array_intersect_key($data, array_flip($fields)))->save();

        $changed = [];
        foreach ($fields as $field) {
            $after = $this->value($activity, $field);
            if ($after !== $before[$field]) {
                $changed[] = $field;
                $this->revision($activity, $user, $field, $before[$field], $after);
            }
        }

        return $changed;
    }

    /**
     * A reason only means something beside an adjustment; blank text is no text.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        foreach (['adjustment_reason', 'notes'] as $field) {
            if (array_key_exists($field, $data) && trim((string) $data[$field]) === '') {
                $data[$field] = null;
            }
        }

        if ((int) ($data['date_adjustment_days'] ?? 0) === 0) {
            $data['adjustment_reason'] = null;
        }

        return $data;
    }

    /** A field as it is written to the audit trail. */
    private function value(Activity $activity, string $field): ?string
    {
        $value = $activity->getAttribute($field);

        return match (true) {
            $value === null => null,
            $value instanceof CarbonInterface => $value->toDateString(),
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        };
    }

    private function revision(Activity $activity, User $user, string $field, ?string $old, ?string $new): void
    {
        ActivityRevision::create([
            'activity_id' => $activity->id,
            // kept when the activity is deleted and activity_id is nulled
            'activity_snapshot_id' => $activity->id,
            'user_id' => $user->id,
            'field' => $field,
            'old_value' => $old,
            'new_value' => $new,
        ]);
    }
}
