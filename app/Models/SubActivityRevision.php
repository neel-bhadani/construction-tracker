<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit row for a user-made change to a sub-activity, as ActivityRevision is for activities.
 * sub_activity_id is null once its activity has been deleted; sub_activity_snapshot_id keeps the id.
 */
#[Fillable(['sub_activity_id', 'sub_activity_snapshot_id', 'user_id', 'field', 'old_value', 'new_value'])]
class SubActivityRevision extends Model
{
    const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SubActivity, $this>
     */
    public function subActivity(): BelongsTo
    {
        return $this->belongsTo(SubActivity::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
