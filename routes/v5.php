<?php

use App\Http\Controllers\V5\HomeController;
use Illuminate\Support\Facades\Route;

Route::middleware('v5.authenticated')->group(function () {
    Route::get('/', HomeController::class)->name('home');
    Route::get('/coolify/version', [HomeController::class, 'coolifyCliVersion'])->name('coolify.version');
});
