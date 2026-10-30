<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One node in a building's dependency graph.
 *
 * planned_day and planned_date are derived and written only by ScheduleRecalculator. They are
 * deliberately not fillable, and must not gain mutators, observers or model events. Create
 * activities through the service that writes a placeholder and recalculates in the same
 * transaction, never with a bare Activity::create().
 *
 * work_number and work_id ("SV-012") are given once, on create, by WorkIds, and never change.
 *
 * With sub-activities, actual_date is theirs to set (SubActivityEditor): the latest done date once
 * all are at 100%, none before. Without, it is recorded by hand as always.
 *
 * predecessor_id is ON DELETE RESTRICT: an activity with dependants cannot be deleted, and
 * hard-deleting its building fails while the chain is intact. See Building.
 */
#[Fillable([
    'building_id',
    'description',
    'predecessor_id',
    'offset_days',
    'date_adjustment_days',
    'adjustment_reason',
    'is_break',
    'actual_date',
    'sort_order',
    'notes',
])]
class Activity extends Model
{
    /**
     * Route binding skips activities whose building has been deleted: its timeline has left the
     * app, so its rows cannot be edited by URL either.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this->where($field ?? $this->getRouteKeyName(), $value)->whereHas('building')->first();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'work_number' => 'integer',
            'next_sub_number' => 'integer',
            'offset_days' => 'integer',
            'date_adjustment_days' => 'integer',
            'is_break' => 'boolean',
            'planned_day' => 'integer',
            'planned_date' => 'date',
            'actual_date' => 'date',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Building, $this>
     */
    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(Activity::class, 'predecessor_id');
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function dependants(): HasMany
    {
        return $this->hasMany(Activity::class, 'predecessor_id');
    }

    /**
     * Its progress bar, 0 to 100: the average of its sub-activities' progress, or, without any,
     * 100 once it has an actual date and 0 before. A break has none. Reads subActivities, so
     * load it first for a list of activities.
     */
    public function progressPercent(): ?float
    {
        if ($this->is_break) {
            return null;
        }

        return $this->subActivities->isEmpty()
            ? ($this->actual_date === null ? 0.0 : 100.0)
            : (float) $this->subActivities->avg('progress');
    }

    /**
     * Its live sub-activities, in the order they are shown.
     *
     * @return HasMany<SubActivity, $this>
     */
    public function subActivities(): HasMany
    {
        return $this->hasMany(SubActivity::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<ActivityRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(ActivityRevision::class);
    }
}
