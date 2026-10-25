<?php

namespace Tests\Feature;

use App\Models\Building;
use App\Services\BuildingChartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WorkbookOracle;
use Tests\TestCase;

/**
 * Every point of every progress-chart series, for every building, compared with Excel's cached
 * values in the workbook's Calc sheet: planned A/B, actual D/E, the pace projections G/H and J/K,
 * hold lead M/N. Dates exactly, fractions to 1e-12.
 */
class WorkbookChartTest extends TestCase
{
    use RefreshDatabase;

    private const FLOAT_TOLERANCE = 1e-12;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedClientWorkbooks();
        $this->travelTo('2026-10-15 12:00');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function scopes(): array
    {
        return ['Tower A' => ['Tower A'], 'Tower B' => ['Tower B'], 'Overall Timeline' => ['Overall Timeline']];
    }

    #[DataProvider('scopes')]
    public function test_every_chart_point_matches_the_calc_sheet(string $scope): void
    {
        $expected = WorkbookOracle::calc($scope);
        $rows = app(BuildingChartService::class)->rows(Building::where('name', $scope)->sole());
        $mismatches = [];
        $compared = 0;

        foreach ($expected as $series => $points) {
            $this->assertCount(count($points), $rows[$series], "{$scope} {$series}: number of rows");

            foreach ($points as $index => [$date, $fraction]) {
                [$actualDate, $actualFraction] = $rows[$series][$index];
                $compared += 2;

                if ($actualDate !== $date) {
                    $mismatches[] = "{$series} row ".($index + 4).": date Excel {$date}, computed {$actualDate}";
                }
                if (abs($actualFraction - $fraction) > self::FLOAT_TOLERANCE) {
                    $mismatches[] = "{$series} row ".($index + 4).": % Excel {$fraction}, computed {$actualFraction}";
                }
            }
        }

        $this->assertSame([], $mismatches);
        // two per row: (52 + 1) * 3 + 2 * 2 rows for the towers, (47 + 1) * 3 + 2 * 2 for Overall
        $this->assertSame($scope === 'Overall Timeline' ? 296 : 326, $compared);
    }
}
