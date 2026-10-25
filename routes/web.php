<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\BuildingController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

/*
 | One account, its own data. Every route that names a project, building or activity is checked
 | against the project's owner by its policy, and answers 404 to anyone else. OwnershipTest walks
 | this list and fails for a route it has no case for.
 |
 | auth.session ends every other session of an account whose password has changed.
 */
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::redirect('/', '/projects')->name('main');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'password'])->middleware('throttle:6,1')->name('profile.password');

    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
    Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
    Route::patch('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
    Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');

    // buildings belong to their project: a building id under the wrong project is a 404
    Route::scopeBindings()->group(function () {
        Route::post('projects/{project}/buildings', [BuildingController::class, 'store'])->name('buildings.store');
        Route::get('projects/{project}/buildings/{building}', [BuildingController::class, 'show'])->name('buildings.show');
        Route::get('projects/{project}/buildings/{building}/chart', [BuildingController::class, 'chart'])->name('buildings.chart');
        Route::patch('projects/{project}/buildings/{building}', [BuildingController::class, 'update'])->name('buildings.update');
        // moves every planned date in the building
        Route::patch('projects/{project}/buildings/{building}/dates', [BuildingController::class, 'dates'])->name('buildings.dates');
        Route::patch('projects/{project}/buildings/{building}/move', [BuildingController::class, 'move'])->name('buildings.move');
        Route::delete('projects/{project}/buildings/{building}', [BuildingController::class, 'destroy'])->name('buildings.destroy');
    });

    // the first step of "Import from Excel": upload, get the sheet names back
    Route::post('imports', [ImportController::class, 'store'])->middleware('throttle:20,1')->name('imports.store');

    // links from before projects existed
    Route::get('scopes/{building}', [BuildingController::class, 'legacy'])->name('scopes.legacy');

    // what happened on site
    Route::patch('activities/{activity}/actual', [ActivityController::class, 'recordActual'])->name('activities.actual');

    // the plan
    Route::post('buildings/{building}/activities', [ActivityController::class, 'store'])->name('activities.store');
    Route::patch('activities/{activity}', [ActivityController::class, 'update'])->name('activities.update');
    Route::delete('activities/{activity}', [ActivityController::class, 'destroy'])->name('activities.destroy');
    Route::post('buildings/{building}/activities/preview', [ActivityController::class, 'previewNew'])->name('activities.preview-new');
    Route::post('activities/{activity}/preview', [ActivityController::class, 'previewEdit'])->name('activities.preview-edit');
});

require __DIR__.'/auth.php';
