<?php

namespace App\Import;

use App\Models\Building;
use Illuminate\Support\Facades\DB;
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

    /** The client's workbooks, outside the repository: CLIENT_WORKBOOKS_DIR. */
    public static function directory(): string
    {
        return config('timeline.client_workbooks_dir');
    }

    /** Whether all three client workbooks are present. */
    public static function available(): bool
    {
        foreach (self::SOURCES as $file) {
            if (! is_readable(self::directory().'/'.$file)) {
                return false;
            }
        }

        return true;
    }

    /** One of the client's three workbooks, as the seeder imports it. */
    public static function read(string $scope): self
    {
        return self::readFile(self::directory().'/'.self::SOURCES[$scope], $scope, $scope);
    }

    /**
     * Any workbook laid out like the client's: activities from row 4 until column B is empty,
     * the start date in E2. Formulas are parsed, never evaluated.
     *
     * @throws UnexpectedValueException naming the cell when the layout or a formula is not recognised
     */
    public static function readFile(string $path, string $sheetName, ?string $label = null): self
    {
        $reader = IOFactory::createReader('Xlsx');

        if (! in_array($sheetName, $reader->listWorksheetNames($path), true)) {
            throw new UnexpectedValueException("The workbook has no sheet named [{$sheetName}].");
        }

        $reader->setLoadSheetsOnly([$sheetName]);

        $sheet = $reader->load($path)->getSheetByName($sheetName)
            ?? throw new UnexpectedValueException("The workbook has no sheet named [{$sheetName}].");

        $activities = [];

        for ($row = self::FIRST_ROW; $sheet->getCell("B{$row}")->getValue() !== null; $row++) {
            $activities[$row] = self::activity($sheet, $row);
        }

        if ($activities === []) {
            throw new UnexpectedValueException("Sheet [{$sheetName}] has no activities: B".self::FIRST_ROW.' is empty.');
        }

        return new self($label ?? $sheetName, self::date($sheet->getCell('E2')->getValue(), "{$sheetName}!E2"), $activities);
    }

    /**
     * @return list<string>
     */
    public static function sheetNames(string $path): array
    {
        return IOFactory::createReader('Xlsx')->listWorksheetNames($path);
    }

    /**
     * The plan in the shape PlanWriter writes, keyed by workbook row.
     *
     * @return array<int, array{description: string, predecessor: ?int, offset_days: int, date_adjustment_days: int, adjustment_reason: ?string, is_break: bool, sort_order: int, actual_date: ?string}>
     */
    public function plan(bool $withActualDates): array
    {
        return array_map(fn (array $activity) => [
            'description' => $activity['description'],
            'predecessor' => $activity['predecessor_row'],
            'offset_days' => $activity['offset_days'],
            'date_adjustment_days' => $activity['date_adjustment_days'],
            'adjustment_reason' => $activity['adjustment_reason'],
            'is_break' => $activity['is_break'],
            'sort_order' => $activity['row'],
            'actual_date' => $withActualDates ? $activity['actual_date'] : null,
        ], $this->activities);
    }

    /**
     * Compare a building written from this workbook with Excel's own cached values: column C day
     * numbers always, column D dates only when the building starts on the workbook's E2 date.
     *
     * @return list<string> one line per row that disagrees
     */
    public function mismatches(Building $building, bool $compareDates): array
    {
        $stored = DB::table('activities')->where('building_id', $building->id)->get()->keyBy('sort_order');
        $mismatches = [];

        foreach ($this->activities as $row => $activity) {
            $actual = $stored[$row] ?? null;

            if ($actual === null
                || (int) $actual->planned_day !== $activity['expected_day']
                || ($compareDates && $actual->planned_date !== $activity['expected_date'])) {
                $mismatches[] = sprintf(
                    '  %s row %d [%s]: expected day %d / %s, computed day %s / %s',
                    $this->scope, $row, $activity['description'],
                    $activity['expected_day'], $activity['expected_date'], $actual->planned_day ?? 'missing', $actual->planned_date ?? 'missing',
                );
            }
        }

        if ($stored->count() !== count($this->activities)) {
            $mismatches[] = "  {$this->scope}: {$stored->count()} activities stored, ".count($this->activities).' in the workbook';
        }

        return $mismatches;
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
            throw new UnexpectedValueException("Unrecognised chain formula in {$cell}: ".self::shown($formula));
        }

        return [
            'predecessor_row' => (int) $matches[1],
            'offset_days' => self::nonNegative(self::sumTerms($matches[2]), $cell),
        ];
    }

    public static function parseAdjustment(mixed $formula, int $row, string $cell): int
    {
        if (! preg_match('/^=E\$2\+C'.$row.'((?:[+-]\d+)*)$/', (string) $formula, $matches)) {
            throw new UnexpectedValueException("Unrecognised date formula in {$cell}: ".self::shown($formula));
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

    /** A cell's content as a person reads it in an error message. */
    private static function shown(mixed $value): string
    {
        return $value === null || $value === '' ? 'an empty cell' : var_export($value, true);
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
            throw new UnexpectedValueException("Expected a date in {$cell}, found ".self::shown($serial));
        }

        return Date::excelToDateTimeObject((float) $serial)->format('Y-m-d');
    }
}
