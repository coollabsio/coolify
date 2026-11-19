<?php

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
});

it('clears the forced password reset of an invited user who resets the password', function () {
    $user = User::factory()->create([
        'email' => 'invitee@example.com',
        'email_verified_at' => null,
        'force_password_reset' => true,
    ]);

    $this->post(route('password.update'), [
        'token' => Password::createToken($user),
        'email' => $user->email,
        'password' => 'Own-Passw0rd!',
        'password_confirmation' => 'Own-Passw0rd!',
    ])->assertSessionHasNoErrors();

    $user->refresh();
    expect(Hash::check('Own-Passw0rd!', $user->password))->toBeTrue()
        ->and($user->force_password_reset)->toBeFalse()
        ->and($user->isUnusedInvitedAccount())->toBeFalse();
});
