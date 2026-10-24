<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

/**
 * Placeholder main page: the signed-in user's name and role, from the shared auth prop.
 */
class MainController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Main');
    }
}
