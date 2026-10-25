<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * The owner can do everything with a project; to anyone else it does not exist, so the refusal
 * is a 404, never a 403 that would confirm the id is taken.
 */
class ProjectPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Project $project): Response
    {
        return self::owns($user, $project);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Project $project): Response
    {
        return self::owns($user, $project);
    }

    public function delete(User $user, Project $project): Response
    {
        return self::owns($user, $project);
    }

    /** The one check behind every project, building and activity ability. */
    public static function owns(User $user, ?Project $project): Response
    {
        return $project !== null && $project->isOwnedBy($user) ? Response::allow() : Response::denyAsNotFound();
    }
}
