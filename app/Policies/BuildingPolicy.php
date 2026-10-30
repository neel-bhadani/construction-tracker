<?php

namespace App\Policies;

use App\Models\Building;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Everything, start dates included, for the owner of the building's project; a 404 for anyone else.
 */
class BuildingPolicy
{
    public function view(User $user, Building $building): Response
    {
        return $this->owns($user, $building);
    }

    /** A new building in this project. */
    public function create(User $user, Project $project): Response
    {
        return ProjectPolicy::owns($user, $project);
    }

    /** Its activities and its chart: everything inside the timeline. */
    public function update(User $user, Building $building): Response
    {
        return $this->owns($user, $building);
    }

    /** The name. Common activities keep theirs. */
    public function rename(User $user, Building $building): Response
    {
        return $this->notCommon($user, $building, 'The common activities keep their name.');
    }

    /** The position among the project's buildings, which common activities are not part of. */
    public function move(User $user, Building $building): Response
    {
        return $this->notCommon($user, $building, 'The common activities are not one of the buildings to reorder.');
    }

    /** Common activities go only with their project. */
    public function delete(User $user, Building $building): Response
    {
        return $this->notCommon($user, $building, 'The common activities belong to the project and cannot be deleted on their own.');
    }

    /** The start dates, which move every planned date in the building. */
    public function updateDates(User $user, Building $building): Response
    {
        return $this->owns($user, $building);
    }

    /** The owner's own refusal, a 403 with the reason; still a 404 to anyone else. */
    private function notCommon(User $user, Building $building, string $reason): Response
    {
        $owns = $this->owns($user, $building);

        return $owns->denied() || ! $building->isCommon() ? $owns : Response::deny($reason);
    }

    private function owns(User $user, Building $building): Response
    {
        return ProjectPolicy::owns($user, $building->project()->withTrashed()->first());
    }
}
