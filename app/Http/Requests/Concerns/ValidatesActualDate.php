<?php

namespace App\Http\Requests\Concerns;

use App\Models\Building;
use App\Services\ScheduleDays;

/**
 * An actual date records something that happened: not in the future (today in the project's
 * timezone), not before the building's actual start, and never on a break. Shared by the quick
 * "Record actual" form and the activity dialog.
 */
trait ValidatesActualDate
{
    /**
     * @return list<string>
     */
    protected function actualDateRules(Building $building): array
    {
        return [
            'nullable', 'date_format:Y-m-d',
            'before_or_equal:'.$this->today($building),
            'after_or_equal:'.$this->actualStart($building),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function actualDateMessages(Building $building): array
    {
        return [
            'actual_date.date_format' => 'Enter the actual date as a date.',
            'actual_date.before_or_equal' => 'The actual date cannot be in the future.',
            'actual_date.after_or_equal' => 'The actual date cannot be before the building\'s actual start ('.$this->actualStart($building).').',
            'actual_date.prohibited_if_accepted' => 'A break has no actual date.',
            'actual_date.prohibited' => 'A break has no actual date.',
        ];
    }

    private function today(Building $building): string
    {
        return ScheduleDays::toDate(ScheduleDays::currentDay(null, $building->project()->withTrashed()->value('timezone')));
    }

    private function actualStart(Building $building): string
    {
        return ($building->actual_start_date ?? $building->planned_start_date)->toDateString();
    }
}
