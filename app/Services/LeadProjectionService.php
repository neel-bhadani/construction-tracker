<?php

namespace App\Services;

use App\Data\ActivityMetrics;
use App\Data\ActivityProjection;
use App\Data\BuildingMetrics;
use App\Data\LeadProjection;
use App\Enums\ActivityStatus;

/**
 * The workbook's Lead Tracker sheet: apply a lead, current or what-if, to every pending activity.
 */
class LeadProjectionService
{
    /** The Lead Tracker's quick-comparison leads, B16 to B21. */
    public const COMPARISON_LEADS = [-5, 0, 5, 7, 9, 12];

    /**
     * @param  ?int  $whatIfLead  null means use the current lead; 0 is a lead like any other.
     */
    public function project(BuildingMetrics $metrics, ?int $whatIfLead = null): LeadProjection
    {
        $appliedLead = $whatIfLead ?? $metrics->currentLead;                          // C7
        $plannedFinish = $metrics->plannedFinish === null ? null : ScheduleDays::toDay($metrics->plannedFinish); // C10
        $finishWith = fn (?int $lead) => $plannedFinish === null || $lead === null
            ? null
            : ScheduleDays::toDate($plannedFinish - $lead);

        $comparison = [];
        foreach (self::COMPARISON_LEADS as $lead) {
            $comparison[$lead] = $finishWith($lead);                                  // C16 to C21
        }

        return new LeadProjection(
            currentLead: $metrics->currentLead,                                       // C5
            whatIfLead: $whatIfLead,                                                  // C6
            appliedLead: $appliedLead,
            plannedFinish: $metrics->plannedFinish,
            forecastFinish: $finishWith($appliedLead),                                // C11
            daysEarly: $appliedLead,                                                  // C12
            comparison: $comparison,
            activities: $this->activities($metrics->activities, $appliedLead),
        );
    }

    /**
     * NEXT is the first row without an actual date in row order that is not a break. Excel's
     * COUNTBLANK(E$4:E_row)=1 would give it to a break, which can never be marked done; a break
     * without a date is Pending. A blank row after NEXT is Pending, even when a later row has been
     * completed.
     *
     * @param  list<ActivityMetrics>  $activities
     * @return list<ActivityProjection>
     */
    private function activities(array $activities, ?int $appliedLead): array
    {
        $nextFound = false;

        return array_map(function (ActivityMetrics $activity) use ($appliedLead, &$nextFound) {
            if ($activity->actualDate !== null) {
                $status = ActivityStatus::Done;
            } elseif (! $nextFound && ! $activity->isBreak) {
                $status = ActivityStatus::Next;
                $nextFound = true;
            } else {
                $status = ActivityStatus::Pending;
            }

            $leadApplied = $activity->actualDate === null ? $appliedLead : $activity->aheadDays; // G

            return new ActivityProjection(
                id: $activity->id,
                description: $activity->description,
                plannedDate: $activity->plannedDate,
                actualDate: $activity->actualDate,
                leadAchieved: $activity->aheadDays,                                   // E
                status: $status,                                                      // F
                leadApplied: $leadApplied,
                actualOrForecast: match (true) {                                      // H
                    $activity->actualDate !== null => $activity->actualDate,
                    $leadApplied === null => null,
                    default => ScheduleDays::toDate(ScheduleDays::toDay($activity->plannedDate) - $leadApplied),
                },
            );
        }, $activities);
    }
}
