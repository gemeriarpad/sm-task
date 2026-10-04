<?php

use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\CloneErrorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FailedJobController;
use App\Http\Controllers\RepositoryCloneController;
use App\Http\Controllers\RepositoryController;
use App\Http\Controllers\SyncRunController;
use App\Http\Controllers\TargetController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware('auth')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('targets', [TargetController::class, 'index'])->name('targets.index');
    Route::get('targets/create', [TargetController::class, 'create'])->name('targets.create');
    Route::get('targets/check', [TargetController::class, 'check'])->name('targets.check');
    Route::post('targets', [TargetController::class, 'store'])->middleware('precognitive')->name('targets.store');
    Route::post('targets/sync', [TargetController::class, 'syncAll'])->name('targets.sync-all');
    Route::get('targets/{target}', [TargetController::class, 'show'])->name('targets.show');
    Route::post('targets/{target}/sync', [TargetController::class, 'sync'])->name('targets.sync');
    Route::get('targets/{target}/edit', [TargetController::class, 'edit'])->name('targets.edit');
    Route::patch('targets/{target}', [TargetController::class, 'update'])->middleware('precognitive')->name('targets.update');
    Route::delete('targets/{target}', [TargetController::class, 'destroy'])->name('targets.destroy');
    Route::post('targets/{target}/deactivate', [TargetController::class, 'deactivate'])->name('targets.deactivate');
    Route::post('targets/{target}/reactivate', [TargetController::class, 'reactivate'])->name('targets.reactivate');
    Route::delete('targets/{target}/clones', [TargetController::class, 'destroyClones'])->name('targets.clones.destroy');

    Route::get('repositories', [RepositoryController::class, 'index'])->name('repositories.index');
    Route::get('repositories/{repository}', [RepositoryController::class, 'show'])->name('repositories.show');
    Route::post('repositories/{repository}/exclusion', [RepositoryController::class, 'exclude'])->name('repositories.exclude');
    Route::delete('repositories/{repository}/exclusion', [RepositoryController::class, 'include'])->name('repositories.include');
    Route::patch('repositories/{repository}/clone', [RepositoryCloneController::class, 'update'])->name('repositories.clone.update');
    Route::post('repositories/{repository}/clone', [RepositoryCloneController::class, 'refresh'])->name('repositories.clone.refresh');
    Route::delete('repositories/{repository}/clone', [RepositoryCloneController::class, 'destroy'])->name('repositories.clone.destroy');
    Route::get('repositories/{repository}/branches', [RepositoryCloneController::class, 'branches'])->name('repositories.branches');
    Route::get('repositories/{repository}/tree', [RepositoryCloneController::class, 'tree'])->name('repositories.tree');
    Route::get('repositories/{repository}/file', [RepositoryCloneController::class, 'file'])->name('repositories.file');
    Route::get('repositories/{repository}/download', [RepositoryCloneController::class, 'download'])->name('repositories.download');

    Route::get('sync-runs', [SyncRunController::class, 'index'])->name('sync-runs.index');
    Route::get('sync-runs/errors', [SyncRunController::class, 'errors'])->name('sync-runs.errors');
    Route::get('sync-runs/errors/clones', [CloneErrorController::class, 'index'])->name('clone-errors.index');

    Route::get('failed-jobs', [FailedJobController::class, 'index'])->name('failed-jobs.index');
    Route::post('failed-jobs/{uuid}/retry', [FailedJobController::class, 'retry'])->whereUuid('uuid')->name('failed-jobs.retry');
    Route::delete('failed-jobs/{uuid}', [FailedJobController::class, 'destroy'])->whereUuid('uuid')->name('failed-jobs.destroy');

    Route::get('api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::post('api-tokens', [ApiTokenController::class, 'store'])->name('api-tokens.store');
    Route::delete('api-tokens/{tokenId}', [ApiTokenController::class, 'destroy'])->whereNumber('tokenId')->name('api-tokens.destroy');
});

require __DIR__.'/auth.php';
