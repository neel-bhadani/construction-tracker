<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Dates as whole day numbers, the way the workbook does arithmetic on date serials. A date is
 * read as the calendar date it carries; only "today" is an instant, so it goes through currentDay().
 */
final class ScheduleDays
{
    public static function toDay(DateTimeInterface|string $date): int
    {
        $date = $date instanceof DateTimeInterface ? $date->format('Y-m-d') : $date;

        return intdiv(CarbonImmutable::parse($date, 'UTC')->getTimestamp(), 86400);
    }

    /**
     * Today's day number: the calendar date in the given timezone — a project's, else the app's
     * (Asia/Kolkata) — taken before
     * anything else. Converting first matters: at 01:00 in Kolkata it is still yesterday in UTC.
     */
    public static function currentDay(?DateTimeInterface $now = null, ?string $timezone = null): int
    {
        return self::toDay(CarbonImmutable::instance($now ?? CarbonImmutable::now())->setTimezone($timezone ?? config('app.timezone')));
    }

    public static function toDate(int $day): string
    {
        return CarbonImmutable::createFromTimestampUTC($day * 86400)->toDateString();
    }
}
