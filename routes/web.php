<?php

use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/



Route::middleware(['auth'])->group(function () {
    Route::get('/', [HomeController::class, 'show'])->name('home');
    Route::get('/project/{project_uuid}', [ProjectController::class, 'environments'])->name('project.environments');

    Route::get('/project/{project_uuid}/{environment_name}', [ProjectController::class, 'resources'])->name('project.resources');

    Route::get('/project/{project_uuid}/{environment_name}/application/{application_uuid}', [ApplicationController::class, 'configuration'])->name('project.applications.configuration');
    Route::get('/project/{project_uuid}/{environment_name}/application/{application_uuid}/deployment', [ApplicationController::class, 'deployments'])->name('project.applications.deployments');
    Route::get('/project/{project_uuid}/{environment_name}/application/{application_uuid}/deployment/{deployment_uuid}', [ApplicationController::class, 'deployment'])->name('project.applications.deployment');


    // Route::get('/database/{database_uuid}', [ProjectController::class, 'database'])->name('project.database');
    // Route::get('//service/{service_uuid}', [ProjectController::class, 'service'])->name('project.service');

    Route::get('/profile', function () {
        return view('profile');
    });
    Route::get('/update', function () {
        return view('update');
    });
    Route::get('/demo', function () {
        return view('demo');
    });
});
