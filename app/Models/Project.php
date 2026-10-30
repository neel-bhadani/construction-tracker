<?php

namespace App\Models;

use App\Enums\BuildingType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A client project holding several buildings. planned_start_date is only the default start of a
 * new building; each building's own dates drive its timeline. timezone decides "today" for the
 * project's buildings.
 *
 * owner_id is the one account that sees and changes it, its buildings and their activities. Every
 * policy checks it; to everyone else the project does not exist.
 *
 * code starts every Work ID ("SV-001"): from the name's initials unless one is given, unique among
 * the owner's live projects. next_activity_number is the counter behind the numbers; it only goes
 * up, so a deleted activity's number is never used again (WorkIds). Changing the code leaves
 * existing Work IDs as they are.
 */
#[Fillable(['owner_id', 'name', 'code', 'details', 'planned_start_date', 'timezone'])]
class Project extends Model
{
    use SoftDeletes;

    /** The column's default, so a project just created has it before it is reloaded. */
    protected $attributes = ['timezone' => 'Asia/Kolkata'];

    protected static function booted(): void
    {
        static::creating(function (Project $project) {
            $project->code ??= self::freeCode($project->owner_id, self::initials($project->name));
        });
    }

    /** "Shaligram Vrundavan" → SV, "test" → T; up to 8 letters and digits, "P" when there are none. */
    public static function initials(string $name): string
    {
        preg_match_all('/[\p{L}\p{N}]+/u', $name, $words);
        $initials = implode('', array_map(fn (string $word) => mb_substr($word, 0, 1), $words[0]));
        $code = substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($initials)), 0, 8);

        return $code === '' ? 'P' : $code;
    }

    /** $code, or the first of $code2, $code3… that none of the owner's other live projects uses. */
    public static function freeCode(?int $ownerId, string $code, ?int $except = null): string
    {
        $taken = self::where('owner_id', $ownerId)->when($except, fn ($query) => $query->whereKeyNot($except))
            ->pluck('code')->flip();

        $free = $code;
        for ($n = 2; isset($taken[$free]); $n++) {
            $free = substr($code, 0, 8 - strlen((string) $n)).$n;
        }

        return $free;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'owner_id' => 'integer',
            'next_activity_number' => 'integer',
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
     * The project's own timeline of site-wide work, shown on the project page rather than as a
     * building. One per project (ProjectEditor creates it; a unique index keeps it one).
     *
     * @return HasOne<Building, $this>
     */
    public function commonActivities(): HasOne
    {
        return $this->hasOne(Building::class)->where('type', BuildingType::CommonWork);
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
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_id === $user->id;
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
