<?php

namespace App\Data;

/**
 * A building's progress: the Chart and Lead Tracker figures, unchanged.
 */
final readonly class BuildingProgress
{
    public function __construct(
        public BuildingMetrics $metrics,
        public LeadProjection $projection,
    ) {}
}
