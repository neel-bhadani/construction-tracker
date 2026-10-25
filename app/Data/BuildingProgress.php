<?php

namespace App\Data;

/**
 * A building's progress at every level: the Chart and Lead Tracker figures, unchanged, and one row
 * per floor with the whole building's common work last.
 */
final readonly class BuildingProgress
{
    /**
     * @param  list<FloorProgress>  $floors  lowest first, then "Whole building"; empty when the building has no floors
     */
    public function __construct(
        public BuildingMetrics $metrics,
        public LeadProjection $projection,
        public array $floors,
    ) {}
}
