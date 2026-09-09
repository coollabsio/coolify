<?php

use App\Http\Controllers\V5\ApplicationController;
use App\Http\Controllers\V5\ClusterController;
use App\Http\Controllers\V5\DashboardController;
use App\Http\Controllers\V5\ResourceConnectionController;
use App\Http\Controllers\V5\ServerController;
use Illuminate\Support\Facades\Route;

Route::middleware('v5.authenticated')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('/realtime-test', [DashboardController::class, 'realtimeTest'])->name('realtime-test');
    Route::post('/realtime-test', [DashboardController::class, 'broadcastRealtimeTest'])->name('realtime-test.broadcast');
    Route::post('/selection', [DashboardController::class, 'updateSelection'])->name('selection.update');
    Route::post('/applications/nginx', [ApplicationController::class, 'store'])->name('applications.nginx');
    Route::post('/applications/refresh', [ApplicationController::class, 'refresh'])->name('applications.refresh');
    Route::delete('/applications/{application}', [ApplicationController::class, 'destroy'])->name('applications.destroy');
    Route::patch('/applications/{application}/position', [ApplicationController::class, 'updatePosition'])->name('applications.position');
    Route::patch('/applications/{application}/ingress', [ApplicationController::class, 'updateIngress'])->name('applications.ingress');
    Route::get('/applications/{application}/logs', [ApplicationController::class, 'logs'])->name('applications.logs');
    Route::patch('/caddy-ingresses/{server}/position', [ApplicationController::class, 'updateCaddyIngressPosition'])->name('caddy-ingresses.position');
    Route::post('/resource-connections', [ResourceConnectionController::class, 'store'])->name('resource-connections.store');
    Route::patch('/resource-connections/{connection}', [ResourceConnectionController::class, 'update'])->name('resource-connections.update');
    Route::delete('/resource-connections/{connection}', [ResourceConnectionController::class, 'destroy'])->name('resource-connections.destroy');
    Route::get('/clusters', [ClusterController::class, 'index'])->name('clusters.index');
    Route::get('/clusters/{cluster}', [ClusterController::class, 'show'])->name('clusters.show');
    Route::post('/clusters', [ClusterController::class, 'store'])->name('clusters.store');
    Route::delete('/clusters/{cluster}', [ClusterController::class, 'destroy'])->name('clusters.destroy');
    Route::post('/clusters/{cluster}/servers', [ServerController::class, 'store'])->name('clusters.servers.store');
    Route::patch('/clusters/{cluster}/servers/{server}', [ServerController::class, 'update'])->name('clusters.servers.update');
    Route::post('/clusters/{cluster}/servers/{server}/check', [ServerController::class, 'check'])->name('clusters.servers.check');
    Route::post('/clusters/{cluster}/servers/{server}/restart-coold', [ServerController::class, 'restartCoold'])->name('clusters.servers.restart-coold');
    Route::get('/clusters/{cluster}/servers/{server}/coold-logs', [ServerController::class, 'cooldLogs'])->name('clusters.servers.coold-logs');
    Route::get('/clusters/{cluster}/servers/{server}/corrosion-tables', [ServerController::class, 'corrosionTables'])->name('clusters.servers.corrosion-tables');
    Route::get('/clusters/{cluster}/servers/{server}/firewall-rules', [ServerController::class, 'firewallRules'])->name('clusters.servers.firewall-rules');
    Route::post('/clusters/{cluster}/servers/{server}/bootstrap', [ServerController::class, 'bootstrap'])->name('clusters.servers.bootstrap');
    Route::delete('/clusters/{cluster}/servers/{server}', [ServerController::class, 'destroy'])->name('clusters.servers.destroy');
});
