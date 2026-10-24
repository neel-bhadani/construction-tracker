<?php

namespace App\Data;

/**
 * One row of the Chart sheet's per-activity table. Dates are Y-m-d strings.
 */
final readonly class ActivityMetrics
{
    public function __construct(
        public int $id,
        public string $description,
        public int $plannedDay,
        public string $plannedDate,
        public ?string $actualDate,
        public ?int $aheadDays,
        public ?string $forecastAveragePace,
        public ?string $forecastRecentPace,
        public ?string $forecastHoldLead,
    ) {}
}
