<?php

use App\Http\Controllers\V5\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware('v5.authenticated')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('/selection', [DashboardController::class, 'updateSelection'])->name('selection.update');
    Route::get('/clusters', [DashboardController::class, 'clustersIndex'])->name('clusters.index');
    Route::post('/clusters', [DashboardController::class, 'storeCluster'])->name('clusters.store');
});
