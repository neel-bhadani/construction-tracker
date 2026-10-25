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

    /** Name, type and position. */
    public function update(User $user, Building $building): Response
    {
        return $this->owns($user, $building);
    }

    public function delete(User $user, Building $building): Response
    {
        return $this->owns($user, $building);
    }

    /** The start dates, which move every planned date in the building. */
    public function updateDates(User $user, Building $building): Response
    {
        return $this->owns($user, $building);
    }

    private function owns(User $user, Building $building): Response
    {
        return ProjectPolicy::owns($user, $building->project()->withTrashed()->first());
    }
}
