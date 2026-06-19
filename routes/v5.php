<?php

use App\Http\Controllers\V5\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware('v5.authenticated')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('/selection', [DashboardController::class, 'updateSelection'])->name('selection.update');
    Route::get('/clusters', [DashboardController::class, 'clustersIndex'])->name('clusters.index');
    Route::post('/clusters', [DashboardController::class, 'storeCluster'])->name('clusters.store');
    Route::delete('/clusters/{cluster}', [DashboardController::class, 'destroyCluster'])->name('clusters.destroy');
    Route::post('/clusters/{cluster}/servers', [DashboardController::class, 'storeServer'])->name('clusters.servers.store');
    Route::patch('/clusters/{cluster}/servers/{server}', [DashboardController::class, 'updateServer'])->name('clusters.servers.update');
    Route::post('/clusters/{cluster}/servers/{server}/check', [DashboardController::class, 'checkServer'])->name('clusters.servers.check');
    Route::post('/clusters/{cluster}/servers/{server}/bootstrap', [DashboardController::class, 'bootstrapServer'])->name('clusters.servers.bootstrap');
    Route::delete('/clusters/{cluster}/servers/{server}', [DashboardController::class, 'destroyServer'])->name('clusters.servers.destroy');
});
