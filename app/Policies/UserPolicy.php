<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class UserPolicy
{
    /** An unapproved account can do nothing, whatever its role says. */
    public function before(User $user): ?bool
    {
        return $user->approved_at === null ? false : null;
    }

    public function viewPending(User $user): bool
    {
        return $user->role === Role::Admin;
    }

    public function approve(User $user, User $account): Response
    {
        if ($user->role !== Role::Admin) {
            return Response::deny();
        }

        return $account->approved_at === null
            ? Response::allow()
            : Response::deny('That account is already approved.');
    }
}
