<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('prevents oauth-only users from creating a password through the reset flow', function () {
    $user = User::factory()->create([
        'password' => null,
        'is_oauth_only' => true,
    ]);

    expect(fn () => app(ResetUserPassword::class)->reset($user, [
        'password' => 'StrongPass123!',
        'password_confirmation' => 'StrongPass123!',
    ]))->toThrow(ValidationException::class);
});

it('prevents oauth-only users from switching to local password login', function () {
    $user = User::factory()->create([
        'password' => Hash::make('StrongPass123!'),
        'is_oauth_only' => true,
    ]);

    expect(fn () => app(UpdateUserPassword::class)->update($user, [
        'current_password' => 'StrongPass123!',
        'password' => 'EvenStronger123!',
        'password_confirmation' => 'EvenStronger123!',
    ]))->toThrow(ValidationException::class);
});
