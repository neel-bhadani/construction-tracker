<?php

namespace App\Http\Middleware;

use App\Http\Requests\Auth\LoginRequest;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checked on every request, not only at sign-in. LoginRequest never creates a session for an
 * unapproved account; this is the lock that holds if one exists anyway — approved_at cleared in
 * the database, or a session from before it was.
 */
class EnsureUserIsApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->approved_at === null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['approval' => LoginRequest::AWAITING_APPROVAL]);
        }

        return $next($request);
    }
}
