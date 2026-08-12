<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create([
        'id' => 0,
        'is_registration_enabled' => false,
    ]);
});

it('blocks password reset for passwordless oauth users when oauth password login is disabled', function () {
    instanceSettings()->update([
        'is_oauth_password_login_disabled' => true,
    ]);

    $user = User::factory()->create([
        'password' => null,
        'oauth_provider' => 'google',
    ]);

    expect(fn () => app(ResetUserPassword::class)->reset($user, [
        'password' => 'New-password-1!',
        'password_confirmation' => 'New-password-1!',
    ]))->toThrow(HttpException::class);
});
