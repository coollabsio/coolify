<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('prevents oauth-only users from creating a password through the reset flow', function () {
    $newPassword = str_repeat('n', 24);

    $user = User::factory()->create([
        'password' => null,
        'is_oauth_only' => true,
    ]);

    expect(fn () => app(ResetUserPassword::class)->reset($user, [
        'password' => $newPassword,
        'password_confirmation' => $newPassword,
    ]))->toThrow(ValidationException::class);
});

it('prevents oauth-only users from switching to local password login', function () {
    $currentPassword = str_repeat('c', 24);
    $newPassword = str_repeat('n', 24);

    $user = User::factory()->create([
        'password' => Hash::make($currentPassword),
        'is_oauth_only' => true,
    ]);

    expect(fn () => app(UpdateUserPassword::class)->update($user, [
        'current_password' => $currentPassword,
        'password' => $newPassword,
        'password_confirmation' => $newPassword,
    ]))->toThrow(ValidationException::class);
});
