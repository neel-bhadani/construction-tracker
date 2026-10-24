<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    /** An unapproved account can do nothing, whatever its role says. */
    public function before(User $user): ?bool
    {
        return $user->approved_at === null ? false : null;
    }

    public function view(User $user, Project $project): bool
    {
        return true;
    }

    /** planned_start_date and actual_start_date: either one moves every date in the project. */
    public function updateDates(User $user, Project $project): bool
    {
        return $user->role === Role::Admin;
    }
}
