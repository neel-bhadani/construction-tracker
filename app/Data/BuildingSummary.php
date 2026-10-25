<?php

namespace App\Data;

/**
 * A building as its project page shows it. Every figure comes from BuildingMetricsService and
 * LeadProjectionService; expectedHandover is the hold-current-lead forecast, or the planned
 * finish until the building's first actual date.
 */
final readonly class BuildingSummary
{
    public function __construct(
        public int $id,
        public string $name,
        public string $type,
        public string $typeLabel,
        public int $sortOrder,
        public string $plannedStartDate,
        public ?string $actualStartDate,
        public int $activitiesCount,
        public int $daysCompleted,
        public int $totalPlannedDays,
        public float $progress,
        public ?int $currentLead,
        public ?string $next,
        public ?string $expectedHandover,
    ) {}
}
