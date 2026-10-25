<?php

namespace App\Data;

/**
 * A project across its buildings. progress is weighted by work-days: the buildings' completed
 * planned days over their total planned days, so a long tower counts for more than a short one.
 * mostBehind is the building with the lowest current lead, skipping buildings with no actual
 * date yet. expectedHandover is the latest of the buildings' expected handovers.
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
        public ?string $details,
        public string $plannedStartDate,
        public string $timezone,
        public int $buildingsCount,
        public ?float $progress,
        public ?array $mostBehind,
        public ?string $expectedHandover,
        public array $buildings,
    ) {}
}
