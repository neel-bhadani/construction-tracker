<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A project is deleted only once it has no buildings left, so a timeline is never removed as a
 * side effect. The buildings are named.
 */
class ProjectHasBuildingsException extends RuntimeException
{
    /**
     * @param  list<string>  $buildings
     */
    public function __construct(string $project, public readonly array $buildings)
    {
        parent::__construct("[{$project}] still has buildings: ".implode(', ', $buildings).'. Delete them first.');
    }
}
