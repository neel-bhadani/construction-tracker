<?php

namespace Database\Seeders\Workbook;

use App\Import\ScheduleWorkbook;
use UnexpectedValueException;

/**
 * Renders the generated part of the "Seed data" section of docs/database-structure.md from the
 * parsed workbooks, so the docs cannot drift from the import. Regenerate with
 * `php artisan workbook:document`; WorkbookDocumentTest fails while they disagree.
 */
class SeedDataDocument
{
    public const START = '<!-- generated:seed-data:start — do not edit; run php artisan workbook:document -->';

    public const END = '<!-- generated:seed-data:end -->';

    public static function path(): string
    {
        return base_path('docs/database-structure.md');
    }

    public static function render(): string
    {
        $workbooks = array_map(ScheduleWorkbook::read(...), array_keys(ScheduleWorkbook::SOURCES));

        return implode("\n", [
            self::START,
            '',
            ...self::totals($workbooks),
            '',
            '### Durations where the name and the formula disagree',
            '',
            'The duration written at the end of a description, compared with the offset its column C formula',
            'computes. The formula wins; the description is imported unchanged.',
            '',
            ...self::nameMismatches($workbooks),
            '',
            '### Date adjustments',
            '',
            'Column D formulas that add days on top of `start + C`. Each is imported as `date_adjustment_days`',
            'with `adjustment_reason = "'.ScheduleWorkbook::ADJUSTMENT_REASON.'"`. They move that row\'s',
            '`planned_date` only, never its `planned_day` or anything downstream.',
            '',
            ...self::adjustments($workbooks),
            '',
            self::END,
        ]);
    }

    public static function apply(string $document): string
    {
        $start = strpos($document, self::START);
        $end = strpos($document, self::END);

        if ($start === false || $end === false || $end < $start) {
            throw new UnexpectedValueException('Generated seed-data markers not found in '.self::path());
        }

        return substr($document, 0, $start).self::render().substr($document, $end + strlen(self::END));
    }

    /**
     * @param  list<ScheduleWorkbook>  $workbooks
     * @return list<string>
     */
    private static function totals(array $workbooks): array
    {
        $lines = ['| Building | Source | Activities | Total planned days | Planned finish |', '| --- | --- | --- | --- | --- |'];

        foreach ($workbooks as $workbook) {
            $lines[] = sprintf(
                '| %s | `%s` | %d | %d | %s |',
                $workbook->scope,
                ScheduleWorkbook::SOURCES[$workbook->scope],
                count($workbook->activities),
                max(array_column($workbook->activities, 'expected_day')),
                max(array_column($workbook->activities, 'expected_date')),
            );
        }

        return [...$lines, '', "Each building's `planned_start_date` = {$workbooks[0]->startDate}, the workbooks' `E2`."];
    }

    /**
     * @param  list<ScheduleWorkbook>  $workbooks
     * @return list<string>
     */
    private static function nameMismatches(array $workbooks): array
    {
        $lines = ['| Building | Row | Activity | Formula (C) | Named | Imported `offset_days` |', '| --- | --- | --- | --- | --- | --- |'];

        foreach ($workbooks as $workbook) {
            $found = false;

            foreach ($workbook->activities as $row => $activity) {
                $named = ScheduleWorkbook::namedDuration($activity['description']);

                if ($named !== null && $named !== $activity['offset_days']) {
                    $found = true;
                    $lines[] = sprintf(
                        '| %s | %d | `%s` | `%s` | %d | **%d** |',
                        $workbook->scope, $row, $activity['description'], $activity['chain_formula'], $named, $activity['offset_days'],
                    );
                }
            }

            if (! $found) {
                $lines[] = "| {$workbook->scope} | | *none* | | | |";
            }
        }

        return $lines;
    }

    /**
     * @param  list<ScheduleWorkbook>  $workbooks
     * @return list<string>
     */
    private static function adjustments(array $workbooks): array
    {
        $lines = ['| Building | Row | Activity | Formula (D) | `date_adjustment_days` |', '| --- | --- | --- | --- | --- |'];

        foreach ($workbooks as $workbook) {
            foreach ($workbook->activities as $row => $activity) {
                if ($activity['date_adjustment_days'] !== 0) {
                    $lines[] = sprintf(
                        '| %s | %d | `%s` | `%s` | %+d |',
                        $workbook->scope, $row, $activity['description'], $activity['date_formula'], $activity['date_adjustment_days'],
                    );
                }
            }
        }

        return $lines;
    }
}
