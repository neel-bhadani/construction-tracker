<?php

namespace App\Data;

/**
 * What `timeline:import-json` created, or would have on a dry run (projectId null).
 */
final readonly class PlanImportResult
{
    /**
     * @param  list<array{name: string, type: string, activities: int, actuals: int, breaks: int}>  $buildings
     */
    public function __construct(
        public bool $dryRun,
        public ?int $projectId,
        public string $projectName,
        public array $buildings,
        public int $datesChecked,
        public int $figuresChecked,
    ) {}
}
