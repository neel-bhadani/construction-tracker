<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use Illuminate\Support\Facades\Route;

/*
 | Sign in, register, reset a forgotten password, sign out.
 |
 | Registering makes a working account and signs it in. With REGISTRATION_OPEN
 | false, /register and the old /signup address both answer 404.
 */

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    // rate limited inside LoginRequest, per email and IP
    Route::post('login', [AuthenticatedSessionController::class, 'store']);

    Route::middleware('registration.open')->group(function () {
        // the same page as /login, opened on its Register tab
        Route::get('register', [RegisterController::class, 'create'])->name('register');
        // rate limited inside RegisterRequest, before validation runs
        Route::post('register', [RegisterController::class, 'store'])->name('register.store');
        // the address sign-up had before
        Route::permanentRedirect('signup', '/register');
    });

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
