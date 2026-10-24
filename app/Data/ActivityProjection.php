<?php

namespace App\Data;

use App\Enums\ActivityStatus;

/**
 * One row of the Lead Tracker sheet's per-activity table. Dates are Y-m-d strings.
 */
final readonly class ActivityProjection
{
    public function __construct(
        public int $id,
        public string $description,
        public string $plannedDate,
        public ?string $actualDate,
        public ?int $leadAchieved,
        public ActivityStatus $status,
        public ?int $leadApplied,
        public ?string $actualOrForecast,
    ) {}
}
