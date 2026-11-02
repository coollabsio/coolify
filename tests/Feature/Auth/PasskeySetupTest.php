<?php

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Once::flush();
});

it('registers the fortify passkey routes', function () {
    expect(Route::has('passkey.login-options'))->toBeTrue()
        ->and(Route::has('passkey.login'))->toBeTrue()
        ->and(Route::has('passkey.confirm'))->toBeTrue()
        ->and(Route::has('passkey.store'))->toBeTrue();
});

it('returns a stable 64 character hex user handle', function () {
    $user = User::factory()->create();

    $handle = $user->getPasskeyUserHandle();

    expect($handle)->toMatch('/^[0-9a-f]{64}$/')
        ->and(User::find($user->id)->getPasskeyUserHandle())->toBe($handle);
});

it('enables passkeys by default and stores passkeys for a user', function () {
    $user = User::factory()->create();
    $user->passkeys()->create([
        'name' => 'Laptop',
        'credential_id' => 'credential-1',
        'credential' => ['aaguid' => '00000000-0000-0000-0000-000000000000'],
    ]);

    expect(instanceSettings()->is_passkey_enabled)->toBeTrue()
        ->and($user->hasPasskeysEnabled())->toBeTrue();
});
