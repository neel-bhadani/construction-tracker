<?php

namespace App\Import;

use App\Enums\BuildingType;
use App\Models\Floor;

/**
 * The import's JSON, one building at a time: what `timeline:excel-to-json` writes and what
 * PlanDocumentValidator and PlanImporter read. Dates are Y-m-d; rows are the workbook's rows.
 *
 *   { project {name, timezone},
 *     buildings [ {name, type, planned_start, actual_start, floors [ {name, level} ],
 *                  activities [ {row, position, name, is_break, floor, comes_after_row, offset_days,
 *                                adjustment, planned_date, actual_date, note} ] } ],
 *     expected { "<building name>": {totalPlannedDays, daysCompleted, progress, currentLead,
 *                plannedFinish, latestActual, forecastAveragePace, forecastRecentPace, forecastHoldLead} } }
 */
final class PlanDocument
{
    /** The Chart-sheet figures the import checks against `expected`. */
    public const EXPECTED = [
        'totalPlannedDays', 'daysCompleted', 'progress', 'currentLead', 'plannedFinish', 'latestActual',
        'forecastAveragePace', 'forecastRecentPace', 'forecastHoldLead',
    ];

    /**
     * One building of the JSON from a workbook sheet. planned_date is Excel's own cached D column,
     * so the import can check its recalculation against it.
     *
     * @param  bool  $guessFloors  guess a floor for each activity from its name; otherwise all are Whole building
     * @return array<string, mixed>
     */
    public static function building(ScheduleWorkbook $workbook, BuildingType $type, ?string $actualStart, bool $guessFloors): array
    {
        $floors = [];
        $activities = [];
        $position = 0;

        foreach ($workbook->activities as $row => $activity) {
            $level = $guessFloors && ! $activity['is_break'] ? self::guessLevel($activity['description']) : null;
            if ($level !== null) {
                $floors[$level] = ['name' => Floor::nameForLevel($level), 'level' => $level];
            }

            $activities[] = [
                'row' => $row,
                'position' => ++$position,
                'name' => $activity['description'],
                'is_break' => $activity['is_break'],
                'floor' => $level === null ? null : Floor::nameForLevel($level),
                'comes_after_row' => $activity['predecessor_row'],
                'offset_days' => $activity['offset_days'],
                'adjustment' => $activity['date_adjustment_days'],
                'planned_date' => $activity['expected_date'],
                'actual_date' => $activity['actual_date'],
                'note' => null,
            ];
        }

        ksort($floors);

        return [
            'name' => $workbook->scope,
            'type' => $type->value,
            'planned_start' => $workbook->startDate,
            // the Chart sheet's C5 only when it differs: the same as the planned start is no actual start
            'actual_start' => $actualStart === $workbook->startDate ? null : $actualStart,
            'floors' => array_values($floors),
            'activities' => $activities,
        ];
    }

    /**
     * "GF SLAB", "DAY 1 TO GF SLAB - 180 DAYS" and "GROUND FLOOR WATERPROOFING" are the ground floor,
     * "1st SLAB" and "12th SLAB 15" that floor; anything else is the whole building's.
     */
    public static function guessLevel(string $name): ?int
    {
        if (preg_match('/^(?:(?:DAY\s*1\s+TO\s+)?(?:GF|GROUND)\s+SLAB|GROUND\s+FLOOR)\b/i', trim($name))) {
            return 0;
        }

        return preg_match('/^(\d{1,3})\s*(?:st|nd|rd|th)\s+SLAB\b/i', trim($name), $m) ? (int) $m[1] : null;
    }

    /**
     * The figures `expected` holds, from a Chart sheet's figures or from BuildingMetrics.
     *
     * @param  array<string, mixed>|object  $figures
     * @return array<string, mixed>
     */
    public static function expected(array|object $figures): array
    {
        $figures = (array) $figures;

        return array_combine(self::EXPECTED, array_map(fn (string $key) => $figures[$key] ?? null, self::EXPECTED));
    }
}
