<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REGISTRATION_OPEN=false: the register routes answer as if they did not exist. Checked per
 * request rather than by leaving the routes out, so a cached route file follows the setting too.
 */
class EnsureRegistrationIsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('auth.registration_open'), 404);

        return $next($request);
    }
}
