<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Dashboard'))->name('dashboard');
Route::get('/about', fn () => Inertia::render('About'))->name('about');

// Vue routes
Route::get('/vue-tanstack-test', fn () => Inertia::render('TanStackTest', [
    'username' => 'Test User',
    'notifications_enabled' => true,
]))->name('tanstack.test');
Route::get('/vue-inertia-test', fn () => Inertia::render('InertiaFormTest', [
    'username' => 'Test User',
    'notifications_enabled' => true,
]))->name('inertia.form.test');

// Svelte routes
Route::get('/svelte-way-1', fn () => inertia('Svelte/Way1TanStack', [
    'username' => 'Test User',
    'notifications_enabled' => true,
]))->name('svelte.way.1');

Route::get('/svelte-way-1-with-state', fn () => inertia('Svelte/Way1TanStackWithState', [
    'username' => 'Test User',
    'notifications_enabled' => true,
]))->name('svelte.way.1.with.state');

Route::get('/svelte-way-2', fn () => inertia('Svelte/Way2TanStack', [
    'username' => 'Test User',
    'notifications_enabled' => true,
]))->name('svelte.way.2');

Route::get('/svelte-way-3', fn () => inertia('Svelte/Way3TanStack', [
    'username' => 'Test User',
    'notifications_enabled' => true,
]))->name('svelte.way.3');

Route::get('/svelte-way-4', fn () => inertia('Svelte/Way4InertiaForm', [
    'username' => 'Test User',
    'notifications_enabled' => true,
]))->name('svelte.way.4');

Route::get('/svelte-way-5', fn () => inertia('Svelte/Way5InertiaUseForm', [
    'username' => 'Test User',
    'notifications_enabled' => true,
]))->name('svelte.way.5');

// Test form route
Route::post('/test-form', function (Request $request) {
    $validated = $request->validate([
        'username' => ['required', 'alpha:ascii', 'min:1', 'max:4'],
        'notifications_enabled' => ['boolean'],
    ]);

    Log::info('Test form submitted', [
        'validated_data' => $validated,
    ]);

    // simulate a delay
    sleep(2);

    return redirect()->back()->with('message', 'Settings saved successfully! (This is a flash message from the server)');
})->name('submit.test.form');
