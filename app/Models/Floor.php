<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One floor of a building: "Ground floor", "1st floor", "Basement 1". level orders them, ground 0
 * and basements negative. Activities without a floor are the whole building's (common work).
 *
 * Deleted through FloorEditor only: it moves the floor's activities to the whole building before
 * the soft delete, which on its own would leave them pointing at a floor nobody sees.
 */
#[Fillable(['building_id', 'name', 'level'])]
class Floor extends Model
{
    use SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'level' => 'integer',
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
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * The usual name of a floor at this level: "Ground floor", "1st floor", "12th floor", "Basement 2".
     */
    public static function nameForLevel(int $level): string
    {
        if ($level === 0) {
            return 'Ground floor';
        }
        if ($level < 0) {
            return 'Basement '.abs($level);
        }

        $suffix = in_array($level % 100, [11, 12, 13], true) ? 'th' : match ($level % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };

        return "{$level}{$suffix} floor";
    }
}
