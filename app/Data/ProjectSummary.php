<?php

namespace App\Data;

/**
 * A project across its timelines: its buildings and its Common activities. progress is weighted
 * by work-days: the timelines' completed planned days over their total planned days, so a long
 * tower counts for more than a short one. mostBehind is the timeline with the lowest current lead,
 * skipping those with no actual date yet. expectedHandover is the latest of their expected
 * handovers. buildings are the building cards only; common is the Common activities, or null.
 */
final readonly class ProjectSummary
{
    /**
     * @param  ?array{id: int, name: string, lead: int}  $mostBehind
     * @param  list<BuildingSummary>  $buildings
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $code,
        public ?string $details,
        public string $plannedStartDate,
        public string $timezone,
        public int $buildingsCount,
        public ?float $progress,
        public ?array $mostBehind,
        public ?string $expectedHandover,
        public array $buildings,
        public ?BuildingSummary $common,
    ) {}
}
