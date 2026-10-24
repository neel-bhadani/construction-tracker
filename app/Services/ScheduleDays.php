<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Dates as whole day numbers, the way the workbook does arithmetic on date serials.
 */
final class ScheduleDays
{
    public static function toDay(DateTimeInterface|string $date): int
    {
        $date = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : $date;

        return intdiv(CarbonImmutable::parse($date, 'UTC')->getTimestamp(), 86400);
    }

    public static function toDate(int $day): string
    {
        return CarbonImmutable::createFromTimestampUTC($day * 86400)->toDateString();
    }
}
