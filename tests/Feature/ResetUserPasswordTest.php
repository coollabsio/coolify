<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create([
        'id' => 0,
        'is_registration_enabled' => false,
    ]);
});

it('prevents oauth-only users from setting a password when oauth password login is disabled', function () {
    InstanceSettings::find(0)->update([
        'is_oauth_password_login_disabled' => true,
    ]);

    $user = User::factory()->create([
        'password' => null,
    ]);

    (new ResetUserPassword)->reset($user, [
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);
})->throws(ValidationException::class);

it('allows password users to reset passwords when oauth password login is disabled', function () {
    InstanceSettings::find(0)->update([
        'is_oauth_password_login_disabled' => true,
    ]);

    $user = User::factory()->create([
        'password' => Hash::make('OldPassword123!'),
    ]);

    (new ResetUserPassword)->reset($user, [
        'password' => 'NewPassword123!',
        'password_confirmation' => 'NewPassword123!',
    ]);

    expect(Hash::check('NewPassword123!', $user->fresh()->password))->toBeTrue();
});
