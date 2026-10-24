<?php

namespace App\Exceptions;

use Illuminate\Support\Collection;

class ScheduleCycleException extends InvalidScheduleException
{
    /**
     * Each cycle is listed in schedule order: every activity is followed by the next, and the last by the first.
     *
     * @param  list<list<array{id: int, description: string}>>  $cycles
     */
    public static function forCycles(array $cycles): self
    {
        $descriptions = Collection::make($cycles)->map(function (array $cycle) {
            $names = array_column($cycle, 'description');

            return implode(' → ', [...$names, $names[0]]);
        });

        return new self(
            'Predecessor cycle: '.$descriptions->implode('; '),
            Collection::make($cycles)->flatten(1)->pluck('id')->all(),
        );
    }
}
