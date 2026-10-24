<?php

use App\Http\Controllers\MainController;
use App\Http\Controllers\UserApprovalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'approved'])->group(function () {
    // a placeholder until the main page is built
    Route::get('/', MainController::class)->name('main');

    // admin only, through UserPolicy; the approval modal on the main page will use these
    Route::get('users/pending', [UserApprovalController::class, 'index'])->name('users.pending');
    Route::patch('users/{user}/approve', [UserApprovalController::class, 'approve'])->name('users.approve');
});

require __DIR__.'/auth.php';
