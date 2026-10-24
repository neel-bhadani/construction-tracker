<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single timeline (Tower A, Tower B, Overall Timeline). Overall is a peer, not a roll-up.
 *
 * Deleting a scope that still has a predecessor chain fails: the cascade to activities hits
 * the RESTRICT on activities.predecessor_id. That is intended. When scope deletion is built,
 * do it deliberately in one transaction: null predecessor_id across the scope's activities,
 * then delete the scope.
 */
#[Fillable(['project_id', 'name', 'sort_order'])]
class Scope extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
}
