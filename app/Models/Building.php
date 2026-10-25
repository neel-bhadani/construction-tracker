<?php

namespace App\Models;

use App\Enums\BuildingType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One timeline: a tower, or the project's common (site-wide) work. Each is a peer with its own
 * activities, durations and start dates; common work is never computed from the buildings.
 *
 * Start dates are the building's own. A new building takes its project's planned start unless
 * it is given one — the project's date is only that default, and changing it moves nothing.
 *
 * Deleting a building is a soft delete: its timeline leaves the app, its rows and audit trail
 * stay. A hard delete fails while the predecessor chain is intact (RESTRICT on
 * activities.predecessor_id); null predecessor_id across the building first, in one transaction.
 */
#[Fillable(['project_id', 'name', 'type', 'planned_start_date', 'actual_start_date', 'sort_order'])]
class Building extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Building $building) {
            $building->planned_start_date ??= Project::withTrashed()->find($building->project_id)?->planned_start_date;
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BuildingType::class,
            'planned_start_date' => 'date',
            'actual_start_date' => 'date',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * @return HasMany<BuildingRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(BuildingRevision::class);
    }
}
