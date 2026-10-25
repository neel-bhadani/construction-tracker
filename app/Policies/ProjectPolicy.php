<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * viewer: read. editor and admin: add, edit and delete projects. Roles are global, not per project.
 */
class ProjectPolicy
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

    public function view(User $user, Project $project): bool
    {
        return true;
    }

    public function create(User $user): Response
    {
        return $this->manages($user);
    }

    /** Name, details, timezone and the default start for new buildings — which moves nothing. */
    public function update(User $user, Project $project): Response
    {
        return $this->manages($user);
    }

    public function delete(User $user, Project $project): Response
    {
        return $this->manages($user);
    }

    private function manages(User $user): Response
    {
        return in_array($user->role, [Role::Editor, Role::Admin], true)
            ? Response::allow()
            : Response::deny('Your role can view projects but not change them. Ask an editor or admin.');
    }
}
