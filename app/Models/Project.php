<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A client project holding several buildings. planned_start_date is only the default start of a
 * new building; each building's own dates drive its timeline. timezone decides "today" for the
 * project's buildings.
 */
#[Fillable(['name', 'details', 'planned_start_date', 'timezone'])]
class Project extends Model
{
    use SoftDeletes;

    /** The column's default, so a project just created has it before it is reloaded. */
    protected $attributes = ['timezone' => 'Asia/Kolkata'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'planned_start_date' => 'date',
        ];
    }

    /**
     * @return HasMany<Building, $this>
     */
    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
    }

    /**
     * @return HasManyThrough<Activity, Building, $this>
     */
    public function activities(): HasManyThrough
    {
        return $this->hasManyThrough(Activity::class, Building::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ProjectRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(ProjectRevision::class);
    }
}
