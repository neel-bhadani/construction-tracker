<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Deleting an activity others follow would rewrite the schedule. It is refused, with the
 * dependants named; they are never re-pointed silently.
 */
class ActivityHasDependantsException extends RuntimeException
{
    /**
     * @param  list<string>  $dependants
     */
    public function __construct(string $description, public readonly array $dependants)
    {
        parent::__construct("[{$description}] cannot be deleted while other activities follow it: ".implode(', ', $dependants).'. Change their predecessor first.');
    }
}
