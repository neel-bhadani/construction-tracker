<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A new account that works straight away: it is created, signed in and taken to its (empty)
 * projects. Only open while REGISTRATION_OPEN is true; the routes check that.
 */
class RegisterController extends Controller
{
    /** The sign-in page, opened on its other tab. */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', ['tab' => 'register', 'canRegister' => true]);
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $user = User::create($request->accountAttributes());

        $request->recordAccountCreated();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('projects.index')->with('success', "Welcome, {$user->name}. Create your first project to begin.");
    }
}
