<?php

use App\Models\InstanceSettings;
use App\Models\User;
use App\Support\RootSetupWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');

    InstanceSettings::query()->forceCreate([
        'id' => 0,
        'is_registration_enabled' => true,
    ]);
});

function registerRootAccount(): TestResponse
{
    return test()->post('/register', [
        'name' => 'Root User',
        'email' => 'root@example.com',
        'password' => 'Password1!@',
        'password_confirmation' => 'Password1!@',
    ]);
}

it('blocks the root registration when the setup window was never opened', function () {
    registerRootAccount()->assertSessionHasErrors('email');

    expect(User::count())->toBe(0);
});

it('blocks the root registration after the setup window ends', function () {
    RootSetupWindow::openIfNoUserExists();
    $this->travel(RootSetupWindow::MINUTES * 60 + 1)->seconds();

    registerRootAccount()->assertSessionHasErrors('email');

    expect(User::count())->toBe(0);
});

it('creates the root user during the setup window', function () {
    RootSetupWindow::openIfNoUserExists();
    $this->travel(RootSetupWindow::MINUTES - 1)->minutes();

    registerRootAccount()->assertSessionHasNoErrors();

    expect(User::find(0)?->email)->toBe('root@example.com');
});

it('shows how to open the setup window again when it is closed', function () {
    $this->get('/register')
        ->assertOk()
        ->assertSee('docker restart coolify')
        ->assertDontSee('Create account');
});

it('does not open the setup window when a user exists', function () {
    User::factory()->create(['id' => 0]);

    RootSetupWindow::openIfNoUserExists();

    expect(RootSetupWindow::isOpen())->toBeFalse();
});
