<?php

namespace App\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use UnexpectedValueException;

/**
 * Excel's cached results on a scope's Chart and Lead Tracker sheets: the figures the services
 * reproduce. Each summary cell is located by address and its B-column label is checked, so a moved
 * layout fails loudly instead of reading the wrong cells.
 *
 * The import writes the Chart figures into the JSON as `expected`; the test oracle
 * (tests/Support/WorkbookOracle) reads every figure and row through here too.
 */
final class ChartSheet
{
    public const PREFIXES = ['Tower A' => 'TA', 'Tower B' => 'TB', 'Overall Timeline' => 'OT'];

    /** cell => [label prefix, figure, type] */
    public const CHART = [
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

    public const LEAD = [
        'C5' => ['Current lead', 'currentLead', 'int'],
        'C6' => ['WHAT-IF', 'whatIfLead', 'int'],
        'C7' => ['Lead applied to all pending', 'appliedLead', 'int'],
        'C10' => ['Planned finish', 'plannedFinish', 'date'],
        'C11' => ['Forecast finish with lead applied', 'forecastFinish', 'date'],
        'C12' => ['Days early (+) / late (-) vs plan', 'daysEarly', 'int'],
    ];

    public const CHART_COLUMNS = [
        'B' => ['description', 'string'], 'C' => ['plannedDate', 'date'], 'D' => ['actualDate', 'date'],
        'E' => ['aheadDays', 'int'], 'F' => ['forecastAveragePace', 'date'], 'G' => ['forecastRecentPace', 'date'],
        'H' => ['forecastHoldLead', 'date'],
    ];

    public const LEAD_COLUMNS = [
        'B' => ['description', 'string'], 'C' => ['plannedDate', 'date'], 'D' => ['actualDate', 'date'],
        'E' => ['leadAchieved', 'int'], 'F' => ['status', 'string'], 'G' => ['leadApplied', 'int'],
        'H' => ['actualOrForecast', 'date'],
    ];

    /**
     * The Chart sheet's summary figures for one of the client's workbooks, keyed as in CHART.
     *
     * @return array<string, mixed>
     */
    public static function figuresOf(string $path, string $scope): array
    {
        $prefix = self::PREFIXES[$scope] ?? throw new UnexpectedValueException("No Chart sheet is known for [{$scope}].");
        $reader = IOFactory::createReader('Xlsx');
        $reader->setLoadSheetsOnly(["{$prefix} Chart"]);
        $sheet = $reader->load($path)->getSheetByName("{$prefix} Chart")
            ?? throw new UnexpectedValueException("The workbook has no sheet named [{$prefix} Chart].");

        return self::figures($sheet, self::CHART);
    }

    /**
     * @param  array<string, array{string, string, string}>  $cells
     * @return array<string, mixed>
     */
    public static function figures(Worksheet $sheet, array $cells): array
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
    public static function rows(Worksheet $sheet, int $first, int $count, array $columns): array
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

    public static function cached(Worksheet $sheet, string $cell): mixed
    {
        $cell = $sheet->getCell($cell);

        return $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
    }

    public static function value(Worksheet $sheet, string $cell, string $type): mixed
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
