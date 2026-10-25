<?php

namespace App\Data;

/**
 * The Chart sheet's status, pace and forecast figures for one building. Dates are Y-m-d strings.
 *
 * Figures that need an actual date are null until one exists, where Excel shows #N/A or a
 * meaningless value. previousActual and daysCompletedAtPrevious are null where Excel shows 0.
 */
final readonly class BuildingMetrics
{
    /**
     * @param  list<ActivityMetrics>  $activities  in row order
     */
    public function __construct(
        public string $actualStartDate,
        public int $totalPlannedDays,
        public ?string $plannedFinish,
        public ?string $latestActual,
        public int $daysCompleted,
        public float $progress,
        public ?int $currentLead,
        public ?int $daysSinceLatestActual,
        public ?float $averagePace,
        public ?string $previousActual,
        public ?int $daysCompletedAtPrevious,
        public ?float $recentPace,
        public ?string $forecastAveragePace,
        public ?int $aheadAveragePace,
        public ?string $forecastRecentPace,
        public ?int $aheadRecentPace,
        public ?string $forecastHoldLead,
        public ?int $aheadHoldLead,
        public array $activities,
    ) {}
}
