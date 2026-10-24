<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Pending sign-ups and their approval. No page of its own: the main page's approval modal reads
 * the list and posts the approval.
 */
class UserApprovalController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewPending', User::class);

        return response()->json(
            User::whereNull('approved_at')->orderBy('created_at')->orderBy('id')
                ->get(['id', 'name', 'email', 'created_at'])
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'signed_up_at' => $user->created_at?->toIso8601String(),
                ]),
        );
    }

    public function approve(User $user): RedirectResponse
    {
        Gate::authorize('approve', $user);

        $user->forceFill(['approved_at' => now()])->save();

        return back()->with('success', "{$user->name} can now sign in.");
    }
}
