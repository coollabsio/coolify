<?php

use App\Http\Controllers\V5\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware('v5.authenticated')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/realtime-test', [DashboardController::class, 'realtimeTest'])->name('realtime-test');
    Route::post('/realtime-test', [DashboardController::class, 'broadcastRealtimeTest'])->name('realtime-test.broadcast');
    Route::post('/selection', [DashboardController::class, 'updateSelection'])->name('selection.update');
    Route::post('/applications/nginx', [DashboardController::class, 'storeNginxApplication'])->name('applications.nginx');
    Route::post('/applications/refresh', [DashboardController::class, 'refreshApplications'])->name('applications.refresh');
    Route::delete('/applications/{application}', [DashboardController::class, 'destroyApplication'])->name('applications.destroy');
    Route::patch('/applications/{application}/position', [DashboardController::class, 'updateApplicationPosition'])->name('applications.position');
    Route::patch('/applications/{application}/ingress', [DashboardController::class, 'updateApplicationIngress'])->name('applications.ingress');
    Route::patch('/caddy-ingresses/{server}/position', [DashboardController::class, 'updateCaddyIngressPosition'])->name('caddy-ingresses.position');
    Route::post('/resource-connections', [DashboardController::class, 'storeResourceConnection'])->name('resource-connections.store');
    Route::patch('/resource-connections/{connection}', [DashboardController::class, 'updateResourceConnection'])->name('resource-connections.update');
    Route::delete('/resource-connections/{connection}', [DashboardController::class, 'destroyResourceConnection'])->name('resource-connections.destroy');
    Route::get('/clusters', [DashboardController::class, 'clustersIndex'])->name('clusters.index');
    Route::get('/clusters/{cluster}', [DashboardController::class, 'showCluster'])->name('clusters.show');
    Route::post('/clusters', [DashboardController::class, 'storeCluster'])->name('clusters.store');
    Route::delete('/clusters/{cluster}', [DashboardController::class, 'destroyCluster'])->name('clusters.destroy');
    Route::post('/clusters/{cluster}/servers', [DashboardController::class, 'storeServer'])->name('clusters.servers.store');
    Route::patch('/clusters/{cluster}/servers/{server}', [DashboardController::class, 'updateServer'])->name('clusters.servers.update');
    Route::post('/clusters/{cluster}/servers/{server}/check', [DashboardController::class, 'checkServer'])->name('clusters.servers.check');
    Route::get('/clusters/{cluster}/servers/{server}/coold-logs', [DashboardController::class, 'serverCooldLogs'])->name('clusters.servers.coold-logs');
    Route::post('/clusters/{cluster}/servers/{server}/bootstrap', [DashboardController::class, 'bootstrapServer'])->name('clusters.servers.bootstrap');
    Route::delete('/clusters/{cluster}/servers/{server}', [DashboardController::class, 'destroyServer'])->name('clusters.servers.destroy');
});
