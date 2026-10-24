<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Activity;
use App\Models\User;

/**
 * viewer: read. editor: record and change actual_date and its note — which move no planned
 * date. admin: everything that reshapes the plan: durations, predecessors, adjustments.
 */
class ActivityPolicy
{
    /** An unapproved account can do nothing, whatever its role says. */
    public function before(User $user): ?bool
    {
        return $user->approved_at === null ? false : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Activity $activity): bool
    {
        return true;
    }

    /** actual_date and notes only. */
    public function recordActual(User $user, Activity $activity): bool
    {
        return in_array($user->role, [Role::Editor, Role::Admin], true);
    }

    public function create(User $user): bool
    {
        return $user->role === Role::Admin;
    }

    /** The plan: description, predecessor, offset, adjustment and reason, break flag. */
    public function update(User $user, Activity $activity): bool
    {
        return $user->role === Role::Admin;
    }

    public function delete(User $user, Activity $activity): bool
    {
        return $user->role === Role::Admin;
    }
}
