<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Building;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * viewer: read. editor and admin: add buildings (empty, copied or imported), rename, retype,
 * reorder and delete them. admin only: an existing building's start dates, which move every
 * planned date in it — the same line as offsets and predecessors, so site staff cannot reshape
 * a plan by accident.
 */
class BuildingPolicy
{
    /** An unapproved account can do nothing, whatever its role says. */
    public function before(User $user): ?bool
    {
        return $user->approved_at === null ? false : null;
    }

    public function view(User $user, Building $building): bool
    {
        return true;
    }

    public function create(User $user): Response
    {
        return $this->manages($user);
    }

    /** Name, type and position. */
    public function update(User $user, Building $building): Response
    {
        return $this->manages($user);
    }

    public function delete(User $user, Building $building): Response
    {
        return $this->manages($user);
    }

    public function updateDates(User $user, Building $building): Response
    {
        return $user->role === Role::Admin
            ? Response::allow()
            : Response::deny('Only an admin can change a building\'s start dates: they move every planned date in it.');
    }

    private function manages(User $user): Response
    {
        return in_array($user->role, [Role::Editor, Role::Admin], true)
            ? Response::allow()
            : Response::deny('Your role can view buildings but not change them. Ask an editor or admin.');
    }
}
