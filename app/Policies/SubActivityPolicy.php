<?php

namespace App\Policies;

use App\Models\SubActivity;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * As its activity: everything for the owner of the activity's project, a 404 for anyone else.
 * Adding and reordering are changes to the activity (ActivityPolicy::update).
 */
class SubActivityPolicy
{
    public function update(User $user, SubActivity $sub): Response
    {
        return $this->owns($user, $sub);
    }

    public function delete(User $user, SubActivity $sub): Response
    {
        return $this->owns($user, $sub);
    }

    private function owns(User $user, SubActivity $sub): Response
    {
        $activity = $sub->activity;

        return $activity === null ? Response::denyAsNotFound() : app(ActivityPolicy::class)->update($user, $activity);
    }
}
