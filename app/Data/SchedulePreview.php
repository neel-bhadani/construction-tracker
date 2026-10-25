<?php

namespace App\Data;

/**
 * What a proposed change to an activity would do, computed without writing. Dates are Y-m-d.
 */
final readonly class SchedulePreview
{
    /** How many changed activities are listed; changedCount counts them all. */
    public const LISTED = 5;

    /**
     * @param  list<array{id: int, description: string, before: string, after: string}>  $changed  the first LISTED other activities whose date moves, in row order
     * @param  ?string  $pushes  inserting with "push": the activity relinked to come after the new one, or null when there is none
     */
    public function __construct(
        public string $plannedDate,
        public int $changedCount,
        public ?string $finishBefore,
        public string $finishAfter,
        public array $changed = [],
        public ?string $pushes = null,
    ) {}
}
