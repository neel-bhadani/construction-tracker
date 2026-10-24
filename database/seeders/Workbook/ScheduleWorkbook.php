<?php

namespace Database\Seeders\Workbook;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use UnexpectedValueException;

/**
 * Reads one scope's activity sheet, parsing the chain from the column C and D formulas.
 *
 * Anything that does not match a known formula shape is rejected, never guessed. The expected
 * day and date are Excel's own cached results, not a recalculation of the same formulas, so
 * they are an independent oracle for ScheduleRecalculator.
 */
class ScheduleWorkbook
{
    public const SOURCES = [
        'Tower A' => 'SV_A3.xlsx',
        'Tower B' => 'SV_B3.xlsx',
        'Overall Timeline' => 'SV_Overall3.xlsx',
    ];

    public const ADJUSTMENT_REASON = 'carried from original sheet';

    private const FIRST_ROW = 4;

    private const BREAK_WORDS = ['BREAK', 'DIWALI', 'HOLI', 'FESTIVAL'];

    /**
     * @param  array<int, array{row: int, description: string, predecessor_row: ?int, offset_days: int, date_adjustment_days: int, adjustment_reason: ?string, is_break: bool, actual_date: ?string, expected_day: int, expected_date: string, chain_formula: string, date_formula: string}>  $activities
     */
    private function __construct(
        public readonly string $scope,
        public readonly string $startDate,
        public readonly array $activities,
    ) {}

    public static function directory(): string
    {
        return base_path('docs/workbooks');
    }

    public static function read(string $scope): self
    {
        $sheet = IOFactory::createReader('Xlsx')
            ->load(self::directory().'/'.self::SOURCES[$scope])
            ->getSheetByName($scope)
            ?? throw new UnexpectedValueException("Sheet [{$scope}] not found in ".self::SOURCES[$scope].'.');

        $activities = [];

        for ($row = self::FIRST_ROW; $sheet->getCell("B{$row}")->getValue() !== null; $row++) {
            $activities[$row] = self::activity($sheet, $row);
        }

        return new self($scope, self::date($sheet->getCell('E2')->getValue(), "{$scope}!E2"), $activities);
    }

    /**
     * @return array{predecessor_row: ?int, offset_days: int}
     */
    public static function parseChain(mixed $formula, string $cell): array
    {
        if (is_int($formula) || is_float($formula)) {
            return ['predecessor_row' => null, 'offset_days' => self::nonNegative((int) $formula, $cell)];
        }

        if (! preg_match('/^=C(\d+)((?:[+-]\d+)*)$/', (string) $formula, $matches)) {
            throw new UnexpectedValueException("Unrecognised chain formula in {$cell}: ".var_export($formula, true));
        }

        return [
            'predecessor_row' => (int) $matches[1],
            'offset_days' => self::nonNegative(self::sumTerms($matches[2]), $cell),
        ];
    }

    public static function parseAdjustment(mixed $formula, int $row, string $cell): int
    {
        if (! preg_match('/^=E\$2\+C'.$row.'((?:[+-]\d+)*)$/', (string) $formula, $matches)) {
            throw new UnexpectedValueException("Unrecognised date formula in {$cell}: ".var_export($formula, true));
        }

        return self::sumTerms($matches[1]);
    }

    /**
     * @return array{row: int, description: string, predecessor_row: ?int, offset_days: int, date_adjustment_days: int, adjustment_reason: ?string, is_break: bool, actual_date: ?string, expected_day: int, expected_date: string, chain_formula: string, date_formula: string}
     */
    private static function activity(Worksheet $sheet, int $row): array
    {
        $description = trim((string) $sheet->getCell("B{$row}")->getValue());
        $day = $sheet->getCell("C{$row}");
        $date = $sheet->getCell("D{$row}");
        $actual = $sheet->getCell("E{$row}")->getValue();
        $adjustment = self::parseAdjustment($date->getValue(), $row, "D{$row}");

        return [
            'row' => $row,
            'description' => $description,
            ...self::parseChain($day->getValue(), "C{$row}"),
            'date_adjustment_days' => $adjustment,
            'adjustment_reason' => $adjustment === 0 ? null : self::ADJUSTMENT_REASON,
            'is_break' => (bool) preg_match('/'.implode('|', self::BREAK_WORDS).'/i', $description),
            'actual_date' => $actual === null ? null : self::date($actual, "E{$row}"),
            'expected_day' => (int) ($day->isFormula() ? $day->getOldCalculatedValue() : $day->getValue()),
            'expected_date' => self::date($date->getOldCalculatedValue(), "D{$row} (cached value)"),
            'chain_formula' => (string) $day->getValue(),
            'date_formula' => (string) $date->getValue(),
        ];
    }

    /**
     * The duration written into a description, e.g. 30 in "MASONRY 30" or 180 in "... - 180 DAYS".
     */
    public static function namedDuration(string $description): ?int
    {
        return preg_match('/(\d+)(?:\s*DAYS)?$/i', $description, $matches) ? (int) $matches[1] : null;
    }

    private static function sumTerms(string $terms): int
    {
        preg_match_all('/[+-]\d+/', $terms, $matches);

        return array_sum(array_map('intval', $matches[0]));
    }

    private static function nonNegative(int $offset, string $cell): int
    {
        if ($offset < 0) {
            throw new UnexpectedValueException("Negative offset {$offset} in {$cell}.");
        }

        return $offset;
    }

    private static function date(mixed $serial, string $cell): string
    {
        if (! is_numeric($serial)) {
            throw new UnexpectedValueException("Expected a date serial in {$cell}, got ".var_export($serial, true));
        }

        return Date::excelToDateTimeObject((float) $serial)->format('Y-m-d');
    }
}
