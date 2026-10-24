<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One node in a scope's dependency graph.
 *
 * planned_day and planned_date are derived and written only by ScheduleRecalculator. They are
 * deliberately not fillable, and must not gain mutators, observers or model events. Create
 * activities through the service that writes a placeholder and recalculates in the same
 * transaction, never with a bare Activity::create().
 *
 * predecessor_id is ON DELETE RESTRICT: an activity with dependants cannot be deleted, and
 * deleting its scope fails while the chain is intact. See Scope.
 */
#[Fillable([
    'scope_id',
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
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
     * @return BelongsTo<Scope, $this>
     */
    public function scope(): BelongsTo
    {
        return $this->belongsTo(Scope::class);
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
     * @return HasMany<ActivityRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(ActivityRevision::class);
    }
}
