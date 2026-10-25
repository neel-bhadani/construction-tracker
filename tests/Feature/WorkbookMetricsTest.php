<?php

namespace Tests\Feature;

use App\Data\BuildingMetrics;
use App\Models\Building;
use App\Services\BuildingMetricsService;
use App\Services\LeadProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WorkbookOracle;
use Tests\TestCase;

/**
 * Every figure on every scope's Chart and Lead Tracker sheet, compared with Excel's cached value.
 * The workbook was last calculated on 2026-10-15, which "days since latest milestone" depends on.
 */
class WorkbookMetricsTest extends TestCase
{
    use RefreshDatabase;

    private const CALCULATED_ON = '2026-10-15';

    private const FLOAT_TOLERANCE = 1e-12;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedClientWorkbooks();
        $this->travelTo(self::CALCULATED_ON);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function scopes(): array
    {
        return ['Tower A' => ['Tower A'], 'Tower B' => ['Tower B'], 'Overall Timeline' => ['Overall Timeline']];
    }

    #[DataProvider('scopes')]
    public function test_workbook_was_calculated_on_the_frozen_date(string $scope): void
    {
        $this->assertSame(self::CALCULATED_ON, WorkbookOracle::read($scope)->today);
    }

    #[DataProvider('scopes')]
    public function test_chart_sheet_matches_excel(string $scope): void
    {
        $oracle = WorkbookOracle::read($scope);
        $metrics = $this->metrics($scope);
        $mismatches = [];

        foreach ($oracle->chart as $figure => $expected) {
            $this->compare($mismatches, "Chart {$figure}", $expected, $metrics->{$figure});
        }

        $this->assertCount(count($oracle->chartRows), $metrics->activities);
        foreach ($oracle->chartRows as $index => $expectedRow) {
            $row = $metrics->activities[$index];
            foreach (array_diff_key($expectedRow, ['row' => true]) as $field => $expected) {
                $this->compare($mismatches, "Chart row {$expectedRow['row']} {$field}", $expected, $row->{$field});
            }
        }

        $this->assertSame([], $mismatches);
    }

    #[DataProvider('scopes')]
    public function test_lead_tracker_sheet_matches_excel(string $scope): void
    {
        $oracle = WorkbookOracle::read($scope);
        $projection = app(LeadProjectionService::class)->project($this->metrics($scope), $oracle->lead['whatIfLead']);
        $mismatches = [];

        foreach ($oracle->lead as $figure => $expected) {
            $this->compare($mismatches, "Lead {$figure}", $expected, $projection->{$figure});
        }

        $this->assertSame(LeadProjectionService::COMPARISON_LEADS, array_keys($oracle->comparison));
        $this->compare($mismatches, 'Lead comparison', $oracle->comparison, $projection->comparison);

        $this->assertCount(count($oracle->leadRows), $projection->activities);
        foreach ($oracle->leadRows as $index => $expectedRow) {
            $row = $projection->activities[$index];
            foreach (array_diff_key($expectedRow, ['row' => true]) as $field => $expected) {
                $actual = $field === 'status' ? $row->status->value : $row->{$field};
                $this->compare($mismatches, "Lead row {$expectedRow['row']} {$field}", $expected, $actual);
            }
        }

        $this->assertSame([], $mismatches);
    }

    /**
     * The day-versus-date split: pace forecasts ignore Diwali's +17 nudge, the hold-lead forecast
     * carries it. Reproduced, not reconciled.
     */
    public function test_pace_and_hold_lead_forecasts_split_on_a_nudged_row(): void
    {
        $rows = collect($this->metrics('Tower A')->activities)->keyBy('description');
        $gap = fn (string $description) => now()->parse($rows[$description]->forecastHoldLead)
            ->diffInDays(now()->parse($rows[$description]->forecastAveragePace), absolute: true);

        $this->assertSame(1.0, $gap('1st SLAB'));
        $this->assertSame(20.0, $gap('DIWALI (08-11-2026) BREAK 20'));
        $this->assertSame(4.0, $gap('2nd SLAB 20'));
    }

    private function metrics(string $scope): BuildingMetrics
    {
        return app(BuildingMetricsService::class)->forBuilding(Building::where('name', $scope)->sole());
    }

    /**
     * @param  list<string>  $mismatches
     */
    private function compare(array &$mismatches, string $label, mixed $expected, mixed $actual): void
    {
        $matches = is_float($expected)
            ? is_float($actual) && abs($expected - $actual) <= self::FLOAT_TOLERANCE
            : $expected === $actual;

        if (! $matches) {
            $mismatches[] = "{$label}: Excel ".json_encode($expected).', computed '.json_encode($actual);
        }
    }
}
