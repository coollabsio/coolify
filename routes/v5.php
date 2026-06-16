<?php

use App\Http\Controllers\V5\HomeController;
use Illuminate\Support\Facades\Route;

Route::middleware('v5.authenticated')->group(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::post('/selection', [HomeController::class, 'updateSelection'])->name('selection.update');
    Route::get('/clusters', [HomeController::class, 'clustersIndex'])->name('clusters.index');
    Route::post('/clusters', [HomeController::class, 'storeCluster'])->name('clusters.store');
});
