<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\ProjectDatesController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\UserApprovalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'approved'])->group(function () {
    Route::get('/', [ScheduleController::class, 'index'])->name('main');
    // the selected scope is in the URL, so a link opens the same view
    Route::get('scopes/{scope}', [ScheduleController::class, 'show'])->name('scopes.show');

    // editor and admin: what happened on site
    Route::patch('activities/{activity}/actual', [ActivityController::class, 'recordActual'])->name('activities.actual');

    // admin: the plan
    Route::post('scopes/{scope}/activities', [ActivityController::class, 'store'])->name('activities.store');
    Route::patch('activities/{activity}', [ActivityController::class, 'update'])->name('activities.update');
    Route::delete('activities/{activity}', [ActivityController::class, 'destroy'])->name('activities.destroy');
    Route::post('scopes/{scope}/activities/preview', [ActivityController::class, 'previewNew'])->name('activities.preview-new');
    Route::post('activities/{activity}/preview', [ActivityController::class, 'previewEdit'])->name('activities.preview-edit');
    Route::patch('projects/{project}/dates', [ProjectDatesController::class, 'update'])->name('projects.dates');

    // admin only, through UserPolicy; the pending-users modal on the main page uses these
    Route::get('users/pending', [UserApprovalController::class, 'index'])->name('users.pending');
    Route::patch('users/{user}/approve', [UserApprovalController::class, 'approve'])->name('users.approve');
});

require __DIR__.'/auth.php';
