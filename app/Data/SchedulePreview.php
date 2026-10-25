<?php

namespace App\Data;

/**
 * What a proposed change to an activity would do, computed without writing. Dates are Y-m-d.
 */
final readonly class SchedulePreview
{
    public function __construct(
        public string $plannedDate,
        public int $changedCount,
        public ?string $finishBefore,
        public string $finishAfter,
    ) {}
}
