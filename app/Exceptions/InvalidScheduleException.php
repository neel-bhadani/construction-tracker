<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The predecessor graph of a building cannot be computed. Nothing has been written.
 */
class InvalidScheduleException extends RuntimeException
{
    /**
     * @param  list<int>  $activityIds
     */
    public function __construct(string $message, public readonly array $activityIds)
    {
        parent::__construct($message);
    }

    public static function foreignPredecessor(int $activityId, string $description): self
    {
        return new self("Activity [{$description}] has a predecessor outside its building.", [$activityId]);
    }
}
