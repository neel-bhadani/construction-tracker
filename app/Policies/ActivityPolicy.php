<?php

namespace App\Policies;

use App\Models\Activity;
use App\Models\Building;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Everything, the plan and what happened on site, for the owner of the activity's project; a 404
 * for anyone else.
 */
class ActivityPolicy
{
    public function view(User $user, Activity $activity): Response
    {
        return $this->owns($user, $activity);
    }

    /** actual_date and notes. */
    public function recordActual(User $user, Activity $activity): Response
    {
        return $this->owns($user, $activity);
    }

    /** A new activity in this building. */
    public function create(User $user, Building $building): Response
    {
        return app(BuildingPolicy::class)->update($user, $building);
    }

    /** The plan: description, predecessor, offset, adjustment, break flag, notes. */
    public function update(User $user, Activity $activity): Response
    {
        return $this->owns($user, $activity);
    }

    public function delete(User $user, Activity $activity): Response
    {
        return $this->owns($user, $activity);
    }

    private function owns(User $user, Activity $activity): Response
    {
        $building = $activity->building()->withTrashed()->first();

        return $building === null ? Response::denyAsNotFound() : app(BuildingPolicy::class)->update($user, $building);
    }
}
