<?php

namespace App\Import;

use App\Enums\BuildingType;

/**
 * The import's JSON, one building at a time: what `timeline:excel-to-json` writes and what
 * PlanDocumentValidator and PlanImporter read. Dates are Y-m-d; rows are the workbook's rows.
 *
 *   { project {name, timezone},
 *     buildings [ {name, type, planned_start, actual_start,
 *                  activities [ {row, position, name, is_break, comes_after_row, offset_days,
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
     * @return array<string, mixed>
     */
    public static function building(ScheduleWorkbook $workbook, BuildingType $type, ?string $actualStart): array
    {
        $activities = [];
        $position = 0;

        foreach ($workbook->activities as $row => $activity) {
            $activities[] = [
                'row' => $row,
                'position' => ++$position,
                'name' => $activity['description'],
                'is_break' => $activity['is_break'],
                'comes_after_row' => $activity['predecessor_row'],
                'offset_days' => $activity['offset_days'],
                'adjustment' => $activity['date_adjustment_days'],
                'planned_date' => $activity['expected_date'],
                'actual_date' => $activity['actual_date'],
                'note' => null,
            ];
        }

        return [
            'name' => $workbook->scope,
            'type' => $type->value,
            'planned_start' => $workbook->startDate,
            // the Chart sheet's C5 only when it differs: the same as the planned start is no actual start
            'actual_start' => $actualStart === $workbook->startDate ? null : $actualStart,
            'activities' => $activities,
        ];
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
