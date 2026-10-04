<?php

use App\Enums\ApiAbility;
use App\Http\Controllers\Api\V1\RepositoryController;
use App\Http\Controllers\Api\V1\TargetController;
use App\Http\Controllers\Api\V1\TargetSyncController;
use Illuminate\Support\Facades\Route;

$read = ApiAbility::Read->value;
$sync = ApiAbility::Sync->value;

// Bearer tokens from the "API tokens" page. Every route needs a token with the
// listed ability; a missing one is a 403.
Route::prefix('v1')->name('api.v1.')->middleware(['auth:sanctum', 'throttle:api'])->whereNumber(['target', 'repository'])->group(function () use ($read, $sync) {
    Route::middleware("ability:{$read}")->group(function () {
        Route::get('targets', [TargetController::class, 'index'])->name('targets.index');
        Route::get('targets/{target}', [TargetController::class, 'show'])->name('targets.show');
        Route::get('targets/{target}/repositories', [RepositoryController::class, 'forTarget'])->name('targets.repositories.index');
        Route::get('repositories', [RepositoryController::class, 'index'])->name('repositories.index');
        Route::get('repositories/{repository}', [RepositoryController::class, 'show'])->name('repositories.show');
    });

    // Either ability: a sync-only token (e.g. a CI hook) can follow what it triggered.
    Route::get('targets/{target}/sync', [TargetSyncController::class, 'show'])->middleware("ability:{$read},{$sync}")->name('targets.sync.show');

    Route::middleware("ability:{$sync}")->group(function () {
        Route::post('targets/{target}/sync', [TargetSyncController::class, 'store'])->name('targets.sync.store');
        Route::post('sync', [TargetSyncController::class, 'storeAll'])->name('sync.store');
    });
});
