<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A project is deleted only once it has no buildings and no common activities left, so a timeline
 * is never removed as a side effect. What is left is named.
 */
class ProjectHasBuildingsException extends RuntimeException
{
    /**
     * @param  list<string>  $buildings  building names, and "the 3 common activities"
     */
    public function __construct(string $project, public readonly array $buildings)
    {
        parent::__construct("[{$project}] still has ".implode(', ', $buildings).'. Delete them first.');
    }
}
