<?php

namespace Tests\Support;

use App\Import\ScheduleWorkbook;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use UnexpectedValueException;

/**
 * Excel's cached results from a scope's Chart and Lead Tracker sheets: the figures the services
 * must reproduce. Each summary cell is located by address and its B-column label is checked, so a
 * moved layout fails loudly instead of comparing the wrong cells.
 */
final class WorkbookOracle
{
    private const PREFIXES = ['Tower A' => 'TA', 'Tower B' => 'TB', 'Overall Timeline' => 'OT'];

    /** cell => [label prefix, figure, type] */
    private const CHART = [
        'C5' => ['Actual start date', 'actualStartDate', 'date'],
        'C8' => ['Total planned work-days', 'totalPlannedDays', 'int'],
        'C9' => ['Planned finish date', 'plannedFinish', 'date'],
        'C10' => ['Latest actual milestone date', 'latestActual', 'date'],
        'C11' => ['Planned work-days completed', 'daysCompleted', 'int'],
        'C12' => ['Progress achieved', 'progress', 'float'],
        'C13' => ['Days ahead (+) / behind (-) at latest milestone', 'currentLead', 'int'],
        'C14' => ['Days since latest milestone', 'daysSinceLatestActual', 'int'],
        'C17' => ['Average pace', 'averagePace', 'float'],
        'C18' => ['Previous milestone date', 'previousActual', 'date-or-zero'],
        'C19' => ['Work-days done at previous milestone', 'daysCompletedAtPrevious', 'int-or-zero'],
        'C20' => ['Recent pace', 'recentPace', 'float'],
        'C24' => ['Planned finish', 'plannedFinish', 'date'],
        'C25' => ['Forecast finish - AVERAGE pace', 'forecastAveragePace', 'date'],
        'C26' => ['Ahead (+) / delay (-) vs plan', 'aheadAveragePace', 'int'],
        'C27' => ['Forecast finish - RECENT pace', 'forecastRecentPace', 'date'],
        'C28' => ['Ahead (+) / delay (-) vs plan', 'aheadRecentPace', 'int'],
        'C29' => ['Forecast finish - HOLD current lead', 'forecastHoldLead', 'date'],
        'C30' => ['Ahead (+) / delay (-) vs plan', 'aheadHoldLead', 'int'],
    ];

    private const LEAD = [
        'C5' => ['Current lead', 'currentLead', 'int'],
        'C6' => ['WHAT-IF', 'whatIfLead', 'int'],
        'C7' => ['Lead applied to all pending', 'appliedLead', 'int'],
        'C10' => ['Planned finish', 'plannedFinish', 'date'],
        'C11' => ['Forecast finish with lead applied', 'forecastFinish', 'date'],
        'C12' => ['Days early (+) / late (-) vs plan', 'daysEarly', 'int'],
    ];

    private const CHART_COLUMNS = [
        'B' => ['description', 'string'], 'C' => ['plannedDate', 'date'], 'D' => ['actualDate', 'date'],
        'E' => ['aheadDays', 'int'], 'F' => ['forecastAveragePace', 'date'], 'G' => ['forecastRecentPace', 'date'],
        'H' => ['forecastHoldLead', 'date'],
    ];

    private const LEAD_COLUMNS = [
        'B' => ['description', 'string'], 'C' => ['plannedDate', 'date'], 'D' => ['actualDate', 'date'],
        'E' => ['leadAchieved', 'int'], 'F' => ['status', 'string'], 'G' => ['leadApplied', 'int'],
        'H' => ['actualOrForecast', 'date'],
    ];

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
        $prefix = self::PREFIXES[$scope];
        $book = IOFactory::createReader('Xlsx')->load(ScheduleWorkbook::directory().'/'.ScheduleWorkbook::SOURCES[$scope]);
        $chart = $book->getSheetByName("{$prefix} Chart");
        $lead = $book->getSheetByName("{$prefix} Lead Tracker");
        $count = count(ScheduleWorkbook::read($scope)->activities);

        $comparison = [];
        foreach (range(16, 21) as $row) {
            $comparison[(int) $lead->getCell("B{$row}")->getValue()] = self::value($lead, "C{$row}", 'date');
        }

        $chartFigures = self::figures($chart, self::CHART);

        return new self(
            today: Date::excelToDateTimeObject(
                self::cached($chart, 'C10') + self::cached($chart, 'C14'),
            )->format('Y-m-d'),
            chart: $chartFigures,
            chartRows: self::rows($chart, 53, $count, self::CHART_COLUMNS),
            lead: self::figures($lead, self::LEAD),
            comparison: $comparison,
            leadRows: self::rows($lead, 25, $count, self::LEAD_COLUMNS),
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
        $prefix = self::PREFIXES[$scope];
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
                    self::value($sheet, "{$dateColumn}{$row}", 'date'),
                    (float) self::cached($sheet, "{$fractionColumn}{$row}"),
                ];
            }
        }

        return $series;
    }

    /**
     * @param  array<string, array{string, string, string}>  $cells
     * @return array<string, mixed>
     */
    private static function figures(Worksheet $sheet, array $cells): array
    {
        $figures = [];

        foreach ($cells as $cell => [$label, $figure, $type]) {
            $actualLabel = trim((string) $sheet->getCell('B'.substr($cell, 1))->getValue());

            if (! str_starts_with($actualLabel, $label)) {
                throw new UnexpectedValueException("{$sheet->getTitle()}!B".substr($cell, 1)." is [{$actualLabel}], expected [{$label}…].");
            }

            $figures[$figure] = self::value($sheet, $cell, $type);
        }

        return $figures;
    }

    /**
     * @param  array<string, array{string, string}>  $columns
     * @return list<array<string, mixed>>
     */
    private static function rows(Worksheet $sheet, int $first, int $count, array $columns): array
    {
        $rows = [];

        for ($row = $first; $row < $first + $count; $row++) {
            $values = ['row' => $row];
            foreach ($columns as $column => [$field, $type]) {
                $values[$field] = self::value($sheet, "{$column}{$row}", $type);
            }
            $rows[] = $values;
        }

        return $rows;
    }

    private static function cached(Worksheet $sheet, string $cell): mixed
    {
        $cell = $sheet->getCell($cell);

        return $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
    }

    private static function value(Worksheet $sheet, string $cell, string $type): mixed
    {
        $value = self::cached($sheet, $cell);

        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'string' => trim((string) $value),
            'int' => (int) $value,
            'int-or-zero' => (int) $value === 0 ? null : (int) $value,
            'float' => (float) $value,
            'date' => Date::excelToDateTimeObject($value)->format('Y-m-d'),
            'date-or-zero' => (int) $value === 0 ? null : Date::excelToDateTimeObject($value)->format('Y-m-d'),
        };
    }
}
