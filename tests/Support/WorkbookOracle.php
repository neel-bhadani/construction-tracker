<?php

namespace Tests\Support;

use App\Import\ChartSheet;
use App\Import\ScheduleWorkbook;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use UnexpectedValueException;

/**
 * Excel's cached results from a scope's Chart and Lead Tracker sheets: the figures the services
 * must reproduce. Each summary cell is located by address and its B-column label is checked, so a
 * moved layout fails loudly instead of comparing the wrong cells.
 */
final class WorkbookOracle
{
    /**
     * @param  array<string, mixed>  $chart
     * @param  list<array<string, mixed>>  $chartRows
     * @param  array<string, mixed>  $lead
     * @param  array<int, ?string>  $comparison
     * @param  list<array<string, mixed>>  $leadRows
     */
    private function __construct(
        public readonly string $today,
        public readonly array $chart,
        public readonly array $chartRows,
        public readonly array $lead,
        public readonly array $comparison,
        public readonly array $leadRows,
    ) {}

    public static function read(string $scope): self
    {
        $prefix = ChartSheet::PREFIXES[$scope];
        $book = IOFactory::createReader('Xlsx')->load(ScheduleWorkbook::directory().'/'.ScheduleWorkbook::SOURCES[$scope]);
        $chart = $book->getSheetByName("{$prefix} Chart");
        $lead = $book->getSheetByName("{$prefix} Lead Tracker");
        $count = count(ScheduleWorkbook::read($scope)->activities);

        $comparison = [];
        foreach (range(16, 21) as $row) {
            $comparison[(int) $lead->getCell("B{$row}")->getValue()] = ChartSheet::value($lead, "C{$row}", 'date');
        }

        $chartFigures = ChartSheet::figures($chart, ChartSheet::CHART);

        return new self(
            today: Date::excelToDateTimeObject(
                ChartSheet::cached($chart, 'C10') + ChartSheet::cached($chart, 'C14'),
            )->format('Y-m-d'),
            chart: $chartFigures,
            chartRows: ChartSheet::rows($chart, 53, $count, ChartSheet::CHART_COLUMNS),
            lead: ChartSheet::figures($lead, ChartSheet::LEAD),
            comparison: $comparison,
            leadRows: ChartSheet::rows($lead, 25, $count, ChartSheet::LEAD_COLUMNS),
        );
    }

    /** The Calc sheet's column pairs, by the header in row 3. */
    private const CALC_COLUMNS = [
        'planned' => ['A', 'B', 'Planned date'],
        'actual' => ['D', 'E', 'Actual date'],
        'averagePace' => ['G', 'H', 'Avg-pace proj. date'],
        'recentPace' => ['J', 'K', 'Recent-pace proj. date'],
        'holdLead' => ['M', 'N', 'Hold-lead proj. date'],
    ];

    /**
     * Excel's cached values in the scope's Calc sheet, the table behind its progress charts: one
     * list of [date, fraction] per series, from row 4. The two pace projections are two rows; the
     * others are one row more than there are activities.
     *
     * @return array<string, list<array{string, float}>>
     */
    public static function calc(string $scope): array
    {
        $prefix = ChartSheet::PREFIXES[$scope];
        $sheet = IOFactory::createReader('Xlsx')
            ->load(ScheduleWorkbook::directory().'/'.ScheduleWorkbook::SOURCES[$scope])
            ->getSheetByName("{$prefix} Calc");
        $rows = count(ScheduleWorkbook::read($scope)->activities) + 1;
        $series = [];

        foreach (self::CALC_COLUMNS as $name => [$dateColumn, $fractionColumn, $header]) {
            $actualHeader = trim((string) $sheet->getCell("{$dateColumn}3")->getValue());
            if ($actualHeader !== $header) {
                throw new UnexpectedValueException("{$prefix} Calc!{$dateColumn}3 is [{$actualHeader}], expected [{$header}].");
            }

            $length = in_array($name, ['averagePace', 'recentPace'], true) ? 2 : $rows;
            for ($row = 4; $row < 4 + $length; $row++) {
                $series[$name][] = [
                    ChartSheet::value($sheet, "{$dateColumn}{$row}", 'date'),
                    (float) ChartSheet::cached($sheet, "{$fractionColumn}{$row}"),
                ];
            }
        }

        return $series;
    }
}
