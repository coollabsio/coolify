<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::forceCreate(['id' => 0, 'is_sponsorship_popup_enabled' => false]);
    Team::factory()->create(['id' => 0, 'name' => 'Root Team']);
    User::factory()->create(['id' => 0, 'name' => 'Root', 'email' => 'root@example.com']);
    $this->user = User::factory()->create([
        'name' => 'Leaving User',
        'email' => 'leaving@example.com',
        'password' => Hash::make('password'),
    ]);
    Team::query()->update(['show_boarding' => false]);
    Cache::flush();
});

it('lets a user delete their own account from the profile page', function () {
    $page = visit('/login')
        ->fill('email', 'leaving@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertDontSee('These credentials do not match')
        ->navigate('/profile')
        ->assertSee('Delete account')
        ->screenshot(filename: 'delete-account-profile');

    $page->click('button:has-text("Delete Account")')
        ->assertSee('Confirm Account Deletion?')
        ->screenshot(filename: 'delete-account-modal')
        ->fill('[x-model="userConfirmationText"]', 'leaving@example.com')
        ->screenshot(filename: 'delete-account-confirm-text')
        ->click('button:visible:has-text("Continue")')
        ->fill('[x-model="password"]', 'password')
        ->screenshot(filename: 'delete-account-password');

    // Count the Livewire update requests sent after the final confirmation.
    $page->script("sessionStorage.setItem('livewireUpdates', '0'); const originalFetch = window.fetch; window.fetch = (input, init) => { if (String(input?.url ?? input).includes('/update')) { sessionStorage.setItem('livewireUpdates', String(Number(sessionStorage.getItem('livewireUpdates')) + 1)); } return originalFetch(input, init); };");

    $page->click('button:visible:has-text("Permanently Delete")')
        ->assertPathIs('/login')
        ->screenshot(filename: 'delete-account-done');

    expect($page->script("sessionStorage.getItem('livewireUpdates')"))->toBe('1')
        ->and(User::query()->where('email', 'leaving@example.com')->exists())->toBeFalse();
});
