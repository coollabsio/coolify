<?php

use App\Http\Controllers\InertiaController;
use App\Http\Controllers\ServerController;
use App\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', [InertiaController::class, 'dashboard'])->name('next_dashboard');

    Route::get('/projects', [InertiaController::class, 'projects'])->name('next_projects');
    Route::get('/projects/{project_uuid}', [InertiaController::class, 'project'])->name('next_project');
    Route::get('/projects/{project_uuid}/environments/{environment_uuid}', [InertiaController::class, 'environment'])->name('next_environment');

    Route::get('/servers', [ServerController::class, 'servers'])->name('next_servers');

    Route::get('/servers/{server_uuid}', [ServerController::class, 'server'])->name('next_server');
    Route::post('/servers/{server_uuid}', [ServerController::class, 'server_store'])->name('next_server_store');

    Route::get('/servers/{server_uuid}/connection', [ServerController::class, 'server_connection'])->name('next_server_connection');
    Route::post('/servers/{server_uuid}/connection', [ServerController::class, 'server_connection_store'])->name('next_server_connection_store');
    Route::get('/servers/{server_uuid}/connection/test', [ServerController::class, 'server_connection_test'])->name('next_server_connection_test');

    Route::get('/servers/{server_uuid}/proxy', [ServerController::class, 'server_proxy'])->name('next_server_proxy');
    Route::post('/servers/{server_uuid}/proxy', [ServerController::class, 'server_proxy_store'])->name('next_server_proxy_store');
    Route::get('/servers/{server_uuid}/proxy/start', [ServerController::class, 'server_proxy_start'])->name('next_server_proxy_start');
    Route::get('/servers/{server_uuid}/proxy/stop', [ServerController::class, 'server_proxy_stop'])->name('next_server_proxy_stop');
    Route::get('/servers/{server_uuid}/proxy/restart', [ServerController::class, 'server_proxy_restart'])->name('next_server_proxy_restart');
    Route::get('/servers/{server_uuid}/automations', [ServerController::class, 'server_automations'])->name('next_server_automations');
    Route::post('/servers/{server_uuid}/automations', [ServerController::class, 'server_automations_store'])->name('next_server_automations_store');
});

Route::any('/{any}', function () {
    if (auth()->user()) {
        return redirect(RouteServiceProvider::HOME);
    }

    return redirect()->route('login');
})->where('any', '.*');
