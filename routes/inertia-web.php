<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\InertiaController;
use App\Providers\RouteServiceProvider;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', [InertiaController::class, 'dashboard'])->name('dashboard');
    Route::get('/projects', [InertiaController::class, 'projects'])->name('projects');
})->name('dashboard');

Route::any('/{any}', function () {
    if (auth()->user()) {
        return redirect(RouteServiceProvider::HOME);
    }

    return redirect()->route('login');
})->where('any', '.*');
