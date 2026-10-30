<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One step inside an activity, 0, 25, 50, 75 or 100% done. done_date is set at 100% only.
 *
 * Changed through SubActivityEditor only: every change re-derives the activity's own actual date
 * (all at 100%: the latest done date; otherwise none). work_id ("SV-012.3") and work_number are
 * given once, on create, and never change; position is the order they are shown in.
 */
#[Fillable(['name', 'planned_date', 'progress', 'done_date', 'note'])]
class SubActivity extends Model
{
    use SoftDeletes;

    public const PROGRESS_STEPS = [0, 25, 50, 75, 100];

    /**
     * Route binding skips sub-activities whose activity's building has been deleted, as Activity does.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        return $this->where($field ?? $this->getRouteKeyName(), $value)->whereHas('activity.building')->first();
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
            'position' => 'integer',
            'progress' => 'integer',
            'planned_date' => 'date',
            'done_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Activity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}
