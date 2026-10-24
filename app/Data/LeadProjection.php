<?php

namespace App\Data;

/**
 * The Lead Tracker sheet for one scope. Dates are Y-m-d strings.
 */
final readonly class LeadProjection
{
    /**
     * @param  array<int, ?string>  $comparison  forecast finish keyed by lead
     * @param  list<ActivityProjection>  $activities  in row order
     */
    public function __construct(
        public ?int $currentLead,
        public ?int $whatIfLead,
        public ?int $appliedLead,
        public ?string $plannedFinish,
        public ?string $forecastFinish,
        public ?int $daysEarly,
        public array $comparison,
        public array $activities,
    ) {}
}
