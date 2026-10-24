<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\SignupController;
use Illuminate\Support\Facades\Route;

/*
 | Sign in, sign up, reset a forgotten password, sign out.
 |
 | Sign-up is a request for an account, not an account: the row it writes is a
 | viewer with approved_at null, and LoginRequest refuses it until an admin
 | approves it. There is deliberately no /register; nothing answers there.
 */

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    // rate limited inside LoginRequest, per email and IP
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    // the same page as /login, opened on its Sign up tab
    Route::get('signup', [SignupController::class, 'create'])->name('signup');
    // rate limited inside SignupRequest, before validation runs
    Route::post('signup', [SignupController::class, 'store'])->name('signup.store');
    Route::get('signup/submitted', [SignupController::class, 'submitted'])->name('signup.submitted');

    Route::get('forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('forgot-password', [PasswordResetController::class, 'email'])
        ->middleware('throttle:5,1')
        ->name('password.email');
    Route::get('reset-password/{token}', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::post('reset-password', [PasswordResetController::class, 'update'])
        ->middleware('throttle:5,1')
        ->name('password.store');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
