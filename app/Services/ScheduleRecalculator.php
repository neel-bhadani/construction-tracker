<?php

namespace App\Services;

use App\Exceptions\InvalidScheduleException;
use App\Exceptions\ScheduleCycleException;
use App\Models\Activity;
use App\Models\Scope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of activities.planned_day and activities.planned_date.
 *
 * Always recomputes the whole scope. Do not make this incremental: a full recompute is simple
 * enough to be obviously correct, and correctness is the reason this replaces the spreadsheet.
 */
class ScheduleRecalculator
{
    /**
     * @throws InvalidScheduleException when the graph cannot be computed; nothing is written.
     */
    public function recalculate(Scope $scope): void
    {
        DB::transaction(function () use ($scope) {
            $startDate = $scope->project()->firstOrFail()->planned_start_date;

            $activities = Activity::query()
                ->where('scope_id', $scope->id)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'description', 'predecessor_id', 'offset_days', 'date_adjustment_days', 'sort_order'])
                ->keyBy('id');

            $plannedDays = $this->plannedDays($activities);

            $rows = $activities->map(fn (Activity $activity) => [
                'id' => $activity->id,
                'planned_day' => $plannedDays[$activity->id],
                'planned_date' => $startDate->copy()
                    ->addDays($plannedDays[$activity->id] + $activity->date_adjustment_days)
                    ->toDateString(),
            ]);

            $this->bulkUpdate($rows);
        });
    }

    /**
     * Walk the forest breadth-first from every root. Each activity has at most one predecessor,
     * so any activity not reached from a root is in a cycle or downstream of one.
     *
     * @param  Collection<int, Activity>  $activities
     * @return array<int, int> planned_day keyed by activity id
     */
    private function plannedDays(Collection $activities): array
    {
        $children = [];
        $queue = [];

        foreach ($activities as $activity) {
            if ($activity->predecessor_id === null) {
                $queue[] = $activity->id;
            } elseif ($activities->has($activity->predecessor_id)) {
                $children[$activity->predecessor_id][] = $activity->id;
            } else {
                throw InvalidScheduleException::foreignPredecessor($activity->id, $activity->description);
            }
        }

        $plannedDays = [];

        while ($queue !== []) {
            $activity = $activities[array_shift($queue)];
            $predecessorDay = $activity->predecessor_id === null ? 0 : $plannedDays[$activity->predecessor_id];
            $plannedDays[$activity->id] = $predecessorDay + $activity->offset_days;

            array_push($queue, ...($children[$activity->id] ?? []));
        }

        if (count($plannedDays) < $activities->count()) {
            throw ScheduleCycleException::forCycles($this->findCycles($activities, $plannedDays));
        }

        return $plannedDays;
    }

    /**
     * Follow predecessor pointers from each unreached activity until one repeats. The repeated
     * activity and those after it on the path are the cycle; rows merely hanging off it are not.
     *
     * @param  Collection<int, Activity>  $activities
     * @param  array<int, int>  $reached
     * @return list<list<array{id: int, description: string}>>
     */
    private function findCycles(Collection $activities, array $reached): array
    {
        $settled = $reached;
        $cycles = [];

        foreach ($activities as $start) {
            $path = [];
            $id = $start->id;

            while (! isset($settled[$id]) && ! isset($path[$id])) {
                $path[$id] = count($path);
                $id = $activities[$id]->predecessor_id;
            }

            if (isset($path[$id])) {
                $cycle = array_slice(array_keys($path), $path[$id]);
                $cycles[] = $this->inScheduleOrder($cycle, $activities);
            }

            $settled += $path;
        }

        return $cycles;
    }

    /**
     * The path was walked child → predecessor; report it predecessor → child, starting from the
     * activity listed first in the table so the message is stable.
     *
     * @param  list<int>  $cycle
     * @param  Collection<int, Activity>  $activities
     * @return list<array{id: int, description: string}>
     */
    private function inScheduleOrder(array $cycle, Collection $activities): array
    {
        $cycle = array_reverse($cycle);
        $first = array_search($activities->only($cycle)->keys()->first(), $cycle, true);
        $cycle = [...array_slice($cycle, $first), ...array_slice($cycle, 0, $first)];

        return array_map(fn (int $id) => [
            'id' => $id,
            'description' => $activities[$id]->description,
        ], $cycle);
    }

    /**
     * One statement, two columns. Deliberately bypasses Eloquent so updated_at keeps recording
     * the last user edit, not the last cascade.
     *
     * @param  Collection<int, array{id: int, planned_day: int, planned_date: string}>  $rows
     */
    private function bulkUpdate(Collection $rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $cases = $rows->map(fn () => 'WHEN ? THEN ?')->implode(' ');
        $placeholders = $rows->map(fn () => '?')->implode(', ');

        DB::update(
            "UPDATE activities SET planned_day = CASE id {$cases} END, planned_date = CASE id {$cases} END WHERE id IN ({$placeholders})",
            [
                ...$rows->flatMap(fn (array $row) => [$row['id'], $row['planned_day']]),
                ...$rows->flatMap(fn (array $row) => [$row['id'], $row['planned_date']]),
                ...$rows->pluck('id'),
            ],
        );
    }
}
