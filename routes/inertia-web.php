<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\InertiaController;
use App\Providers\RouteServiceProvider;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', [InertiaController::class, 'dashboard'])->name('next_dashboard');

    Route::get('/projects', [InertiaController::class, 'projects'])->name('next_projects');
    Route::get('/projects/{project_uuid}', [InertiaController::class, 'project'])->name('next_project');
    Route::get('/projects/{project_uuid}/environments/{environment_uuid}', [InertiaController::class, 'environment'])->name('next_environment');
});

Route::any('/{any}', function () {
    if (auth()->user()) {
        return redirect(RouteServiceProvider::HOME);
    }

    return redirect()->route('login');
})->where('any', '.*');
