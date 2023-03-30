<?php

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

    Route::get('/application/{application_uuid}', [ProjectController::class, 'application'])->name('project.application');
    Route::get('/application/{application_uuid}/deployment/{deployment_uuid}', [ProjectController::class, 'deployment'])->name('project.deployment');

    // Route::get('/database/{database_uuid}', [ProjectController::class, 'database'])->name('project.database');
    // Route::get('//service/{service_uuid}', [ProjectController::class, 'service'])->name('project.service');

    Route::get('/profile', function () {
        return view('profile');
    });
    Route::get('/demo', function () {
        return view('demo');
    });
});
