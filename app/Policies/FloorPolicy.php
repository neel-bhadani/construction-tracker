<?php

namespace App\Policies;

use App\Models\Building;
use App\Models\Floor;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * A floor belongs to its building: everything for the owner of the building's project, a 404 for
 * anyone else.
 */
class FloorPolicy
{
    /** New floors in this building. */
    public function create(User $user, Building $building): Response
    {
        return app(BuildingPolicy::class)->update($user, $building);
    }

    /** Name and level. */
    public function update(User $user, Floor $floor): Response
    {
        return $this->owns($user, $floor);
    }

    public function delete(User $user, Floor $floor): Response
    {
        return $this->owns($user, $floor);
    }

    private function owns(User $user, Floor $floor): Response
    {
        $building = $floor->building()->withTrashed()->first();

        return $building === null ? Response::denyAsNotFound() : app(BuildingPolicy::class)->update($user, $building);
    }
}
