<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Dashboard'))->name('dashboard');
Route::get('/about', fn () => Inertia::render('About'))->name('about');
