<?php

namespace App\Http\Controllers\Auth;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SignupRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Asking for an account, not getting one. The row written here cannot sign in until an admin
 * approves it, and nobody is logged in at the end: the person lands on a page that says what
 * happens next.
 */
class SignupController extends Controller
{
    /** The sign-in page, opened on its other tab. */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', ['tab' => 'signup']);
    }

    public function store(SignupRequest $request): RedirectResponse
    {
        $user = new User($request->accountAttributes());

        // set here and nowhere near the request: the form cannot promote or approve itself
        $user->forceFill(['role' => Role::Viewer, 'approved_at' => null])->save();

        $request->recordAccountCreated();

        return redirect()->route('signup.submitted')->with('signedUp', [
            'name' => $user->name,
            'email' => $user->email,
        ]);
    }

    /**
     * Readable on its own — a refresh still explains what happens next — and personal only on
     * the first visit, when the flash says whose request it was.
     */
    public function submitted(): Response
    {
        return Inertia::render('Auth/SignupSubmitted', ['account' => session('signedUp')]);
    }
}
