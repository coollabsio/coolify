<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\InertiaController;
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', [InertiaController::class, 'show'])->name('dashboard');
});
