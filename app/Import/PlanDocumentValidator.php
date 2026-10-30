<?php

namespace App\Import;

use App\Enums\BuildingType;
use App\Exceptions\InvalidScheduleException;
use App\Models\Activity;
use App\Services\ScheduleRecalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Checks an import document (PlanDocument) before anything is written, and reports every problem
 * it finds, each with where it is: "Tower A row 7: …".
 *
 * 1. Shape: Validator rules, as a Form Request would.
 * 2. Each building: rows unique, "comes after" a row of the same building, no row after itself,
 *    breaks without an actual date, no actual date before the start.
 *    At most one building is the common activities (common_work); it fills the project's own.
 * 3. The chain: the recalculator's own computation, in memory. A loop is reported with its rows;
 *    every planned date it gives must equal the JSON's planned_date, which is Excel's.
 *
 * The `expected` figures need the plan written, so PlanImporter checks those inside its transaction.
 */
class PlanDocumentValidator
{
    public function __construct(private readonly ScheduleRecalculator $recalculator) {}

    /**
     * @param  array<string, mixed>  $document
     * @return list<string> empty when it is valid
     */
    public function validate(array $document): array
    {
        $shape = Validator::make($document, [
            'project' => ['required', 'array'],
            'project.name' => ['required', 'string', 'max:150'],
            'project.timezone' => ['required', 'timezone:all'],
            'buildings' => ['required', 'array', 'min:1'],
            'buildings.*.name' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            ...$this->buildingRules('buildings.*.'),
            'expected' => ['required', 'array'],
        ]);

        if ($shape->fails()) {
            return $this->messages($shape->errors()->toArray());
        }

        $problems = [];
        $common = array_filter($document['buildings'], fn (array $building) => $building['type'] === BuildingType::CommonWork->value);
        if (count($common) > 1) {
            $problems[] = 'Only one timeline can be the common activities: '.implode(', ', array_column($common, 'name')).'.';
        }

        foreach ($document['buildings'] as $building) {
            array_push($problems, ...$this->building($building));

            if ($building['type'] === BuildingType::Building->value && mb_strtolower($building['name']) === mb_strtolower(BuildingType::CommonWork->label())) {
                $problems[] = "{$building['name']}: the name is the project's own Common activities; choose another.";
            }

            if (! isset($document['expected'][$building['name']])) {
                $problems[] = "{$building['name']}: no expected figures in `expected`.";
            }
        }

        return $problems;
    }

    /**
     * One building: its own shape, then the checks above. Also what the Excel upload in the UI runs.
     *
     * @param  array<string, mixed>  $building
     * @return list<string>
     */
    public function building(array $building, bool $compareDates = true): array
    {
        $shape = Validator::make($building, [
            'name' => ['required', 'string', 'max:100'],
            ...$this->buildingRules(''),
        ]);
        if ($shape->fails()) {
            return $this->messages($shape->errors()->toArray(), $building['name'] ?? 'A building');
        }

        $name = $building['name'];
        $problems = [];
        $rows = [];
        foreach ($building['activities'] as $activity) {
            if (isset($rows[$activity['row']])) {
                $problems[] = "{$name} row {$activity['row']}: the row number is used twice.";
            }
            $rows[$activity['row']] = $activity;
        }

        $start = $building['actual_start'] ?? $building['planned_start'];
        foreach ($rows as $row => $activity) {
            $after = $activity['comes_after_row'] ?? null;
            $label = "{$name} row {$row} [{$activity['name']}]";

            if ($after !== null && ! isset($rows[$after])) {
                $problems[] = "{$label}: comes after row {$after}, which is not in this building.";
            } elseif ($after === $row) {
                $problems[] = "{$label}: comes after itself.";
            }
            if ($activity['is_break'] && ($activity['actual_date'] ?? null) !== null) {
                $problems[] = "{$label}: a break has no actual date.";
            }
            if (($activity['actual_date'] ?? null) !== null && $activity['actual_date'] < $start) {
                $problems[] = "{$label}: the actual date {$activity['actual_date']} is before the building's start ({$start}).";
            }
        }

        return $problems !== [] ? $problems : $this->chain($building, $rows, $compareDates);
    }

    /**
     * The recalculator's walk over the rows, in memory: a loop, then every planned date.
     *
     * @param  array<string, mixed>  $building
     * @param  array<int, array<string, mixed>>  $rows  keyed by row
     * @return list<string>
     */
    private function chain(array $building, array $rows, bool $compareDates): array
    {
        $graph = collect($rows)->map(fn (array $a, int $row) => (new Activity)->forceFill([
            'id' => $row,
            'description' => "row {$row} [{$a['name']}]",
            'predecessor_id' => $a['comes_after_row'] ?? null,
            'offset_days' => $a['offset_days'],
            'date_adjustment_days' => $a['adjustment'] ?? 0,
            'sort_order' => $a['position'],
        ]))->sortBy('sort_order');

        try {
            $computed = $this->recalculator->compute($graph, CarbonImmutable::parse($building['planned_start']));
        } catch (InvalidScheduleException $exception) {
            return ["{$building['name']}: {$exception->getMessage()}"];
        }

        if (! $compareDates) {
            return [];
        }

        $problems = [];
        foreach ($rows as $row => $activity) {
            if ($computed[$row]['planned_date'] !== $activity['planned_date']) {
                $problems[] = "{$building['name']} row {$row} [{$activity['name']}]: the plan gives {$computed[$row]['planned_date']}, the JSON (Excel) says {$activity['planned_date']}.";
            }
        }

        return $problems;
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function buildingRules(string $prefix): array
    {
        $activity = "{$prefix}activities.*.";

        return [
            "{$prefix}type" => ['required', Rule::enum(BuildingType::class)],
            "{$prefix}planned_start" => ['required', 'date_format:Y-m-d'],
            "{$prefix}actual_start" => ['nullable', 'date_format:Y-m-d'],
            "{$prefix}activities" => ['required', 'array', 'min:1'],
            "{$activity}row" => ['required', 'integer', 'min:1'],
            "{$activity}position" => ['required', 'integer', 'min:1'],
            "{$activity}name" => ['required', 'string', 'max:255'],
            "{$activity}is_break" => ['required', 'boolean'],
            "{$activity}comes_after_row" => ['nullable', 'integer'],
            "{$activity}offset_days" => ['required', 'integer', 'min:0', 'max:65535'],
            "{$activity}adjustment" => ['required', 'integer', 'min:-32768', 'max:32767'],
            "{$activity}planned_date" => ['required', 'date_format:Y-m-d'],
            "{$activity}actual_date" => ['nullable', 'date_format:Y-m-d'],
            "{$activity}note" => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * Validator messages as "where: what", the field path made readable.
     *
     * @param  array<string, list<string>>  $errors
     * @return list<string>
     */
    private function messages(array $errors, ?string $building = null): array
    {
        $lines = [];
        foreach ($errors as $path => $messages) {
            $where = preg_replace(['/^buildings\.(\d+)\./', '/activities\.(\d+)\./'], ['building $1, ', 'activity $1, '], $path);
            foreach ($messages as $message) {
                $lines[] = ($building === null ? '' : "{$building}: ")."{$where}: {$message}";
            }
        }

        return $lines;
    }
}
