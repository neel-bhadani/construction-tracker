<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable(['name', 'planned_start_date', 'actual_start_date'])]
class Project extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'planned_start_date' => 'date',
            'actual_start_date' => 'date',
        ];
    }

    /**
     * @return HasMany<Scope, $this>
     */
    public function scopes(): HasMany
    {
        return $this->hasMany(Scope::class);
    }

    /**
     * @return HasManyThrough<Activity, Scope, $this>
     */
    public function activities(): HasManyThrough
    {
        return $this->hasManyThrough(Activity::class, Scope::class);
    }
}
