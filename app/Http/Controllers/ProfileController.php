<?php

namespace App\Http\Controllers;

use App\Http\Requests\PasswordUpdateRequest;
use App\Http\Requests\ProfileRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My profile": the signed-in account's own details and password. Nobody edits anyone else's.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'profile' => ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone],
        ]);
    }

    public function update(ProfileRequest $request): RedirectResponse
    {
        $request->user()->fill($request->details())->save();

        return back()->with('success', 'Your details have been saved.');
    }

    /**
     * The new password is hashed by the model's cast. logoutOtherDevices() hashes it again for
     * the session check, so every other session and "keep me signed in" cookie of this account
     * fails auth.session on its next request; this one is kept.
     */
    public function password(PasswordUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();
        $user->forceFill(['password' => $request->validated('password')])->save();

        Auth::logoutOtherDevices($request->validated('password'));

        return back()->with('success', 'Password changed. You have been signed out everywhere else.');
    }
}
