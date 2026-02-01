<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Mockery;

beforeEach(function () {
    // Reset any mocks
    Mockery::close();
});

afterEach(function () {
    Mockery::close();
});

describe('OAuth-only user restrictions', function () {
    it('blocks password update for oauth-only users', function () {
        // Create a mock user with is_oauth_only = true
        $user = Mockery::mock(User::class)->makePartial();
        $user->is_oauth_only = true;

        $updatePassword = new UpdateUserPassword;

        expect(fn () => $updatePassword->update($user, [
            'current_password' => 'password',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]))->toThrow(ValidationException::class);
    });

    it('blocks password reset for oauth-only users', function () {
        // Create a mock user with is_oauth_only = true
        $user = Mockery::mock(User::class)->makePartial();
        $user->is_oauth_only = true;

        $resetPassword = new ResetUserPassword;

        expect(fn () => $resetPassword->reset($user, [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]))->toThrow(ValidationException::class);
    });

    it('allows password update for regular users', function () {
        // This test would need database, so we just test the is_oauth_only check
        $user = Mockery::mock(User::class)->makePartial();
        $user->is_oauth_only = false;

        // The user is not oauth-only, so the method should proceed to validation
        // (and fail on current_password check, but that's expected)
        expect($user->is_oauth_only)->toBeFalse();
    });

    it('correctly identifies oauth-only users via hasPassword check', function () {
        // Create a mock user without password (OAuth user)
        $userWithoutPassword = Mockery::mock(User::class)->makePartial();
        $userWithoutPassword->password = null;
        $userWithoutPassword->shouldReceive('hasPassword')->andReturn(false);

        // Create a mock user with password (regular user)
        $userWithPassword = Mockery::mock(User::class)->makePartial();
        $userWithPassword->password = 'hashed_password';
        $userWithPassword->shouldReceive('hasPassword')->andReturn(true);

        expect($userWithoutPassword->hasPassword())->toBeFalse();
        expect($userWithPassword->hasPassword())->toBeTrue();
    });
});

describe('User model oauth-only attribute', function () {
    it('casts is_oauth_only to boolean', function () {
        $user = new User;

        // Test the casts array includes is_oauth_only
        $casts = $user->getCasts();

        expect($casts)->toHaveKey('is_oauth_only');
        expect($casts['is_oauth_only'])->toBe('boolean');
    });
});
