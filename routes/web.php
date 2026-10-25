<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\BuildingController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\UserApprovalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'approved'])->group(function () {
    Route::redirect('/', '/projects')->name('main');

    // projects: editor and admin manage, everyone approved reads
    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    // buildings belong to their project: a building id under the wrong project is a 404
    Route::scopeBindings()->group(function () {
        Route::post('projects/{project}/buildings', [BuildingController::class, 'store'])->name('buildings.store');
        Route::get('projects/{project}/buildings/{building}', [BuildingController::class, 'show'])->name('buildings.show');
        Route::patch('projects/{project}/buildings/{building}', [BuildingController::class, 'update'])->name('buildings.update');
        // admin only: moves every planned date in the building
        Route::patch('projects/{project}/buildings/{building}/dates', [BuildingController::class, 'dates'])->name('buildings.dates');
        Route::patch('projects/{project}/buildings/{building}/move', [BuildingController::class, 'move'])->name('buildings.move');
        Route::delete('projects/{project}/buildings/{building}', [BuildingController::class, 'destroy'])->name('buildings.destroy');
    });

    // the first step of "Import from Excel": upload, get the sheet names back
    Route::post('imports', [ImportController::class, 'store'])->middleware('throttle:20,1')->name('imports.store');

    // links from before projects existed
    Route::get('scopes/{building}', [BuildingController::class, 'legacy'])->name('scopes.legacy');

    // editor and admin: what happened on site
    Route::patch('activities/{activity}/actual', [ActivityController::class, 'recordActual'])->name('activities.actual');

    // admin: the plan
    Route::post('buildings/{building}/activities', [ActivityController::class, 'store'])->name('activities.store');
    Route::patch('activities/{activity}', [ActivityController::class, 'update'])->name('activities.update');
    Route::delete('activities/{activity}', [ActivityController::class, 'destroy'])->name('activities.destroy');
    Route::post('buildings/{building}/activities/preview', [ActivityController::class, 'previewNew'])->name('activities.preview-new');
    Route::post('activities/{activity}/preview', [ActivityController::class, 'previewEdit'])->name('activities.preview-edit');

    // admin only, through UserPolicy; the pending-users modal uses these
    Route::get('users/pending', [UserApprovalController::class, 'index'])->name('users.pending');
    Route::patch('users/{user}/approve', [UserApprovalController::class, 'approve'])->name('users.approve');
});

require __DIR__.'/auth.php';
