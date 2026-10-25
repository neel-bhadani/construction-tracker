<?php

namespace App\Data;

use App\Enums\FloorStatus;

/**
 * One floor's progress, or the whole building's common work (id and level null). Work-days, not
 * a count of activities, so a 60-day slab weighs more than a 5-day fitting. Dates are Y-m-d.
 *
 * Breaks are on no floor and count nowhere here: they have no work-days and are never done.
 */
final readonly class FloorProgress
{
    public function __construct(
        public ?int $id,
        public string $name,
        public ?int $level,
        public int $activitiesCount,
        public int $doneCount,
        public int $workDays,
        public int $workDaysDone,
        /** null when the floor has no work-days to measure */
        public ?float $progress,
        public ?string $plannedFinish,
        /** once every activity on it is done: the latest actual date */
        public ?string $actualFinish,
        /** until then: the Lead Tracker's actual-or-forecast, latest on the floor; null before any actual */
        public ?string $forecastFinish,
        public FloorStatus $status,
    ) {}
}
