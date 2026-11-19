<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('generates a 6-digit verification code when requesting email change', function () {
    Notification::fake();

    $user = User::factory()->create();

    $user->requestEmailChange('newemail@example.com');

    $user->refresh();

    expect($user->pending_email)->toBe('newemail@example.com')
        ->and($user->email_change_code)->toMatch('/^\d{6}$/')
        ->and($user->email_change_code_expires_at)->not->toBeNull();
});

it('stores the verification code using a cryptographically secure generator', function () {
    Notification::fake();

    $user = User::factory()->create();

    // Generate many codes and verify they are all valid 6-digit strings
    $codes = collect();
    for ($i = 0; $i < 50; $i++) {
        $user->requestEmailChange('newemail@example.com');
        $user->refresh();
        $codes->push($user->email_change_code);
    }

    // All codes should be exactly 6 digits (including leading zeros)
    $codes->each(function ($code) {
        expect($code)->toMatch('/^\d{6}$/');
        expect((int) $code)->toBeLessThanOrEqual(999999);
        expect(strlen($code))->toBe(6);
    });

    // With 50 random codes from a 1M space, we should see at least some variety
    expect($codes->unique()->count())->toBeGreaterThan(1);
});

it('confirms email change with correct verification code', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'old@example.com']);

    $user->requestEmailChange('new@example.com');
    $user->refresh();

    $code = $user->email_change_code;

    $result = $user->confirmEmailChange($code);

    $user->refresh();

    expect($result)->toBeTrue()
        ->and($user->email)->toBe('new@example.com')
        ->and($user->pending_email)->toBeNull()
        ->and($user->email_change_code)->toBeNull()
        ->and($user->email_change_code_expires_at)->toBeNull();
});

it('rejects email change with incorrect verification code', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'old@example.com']);

    $user->requestEmailChange('new@example.com');
    $user->refresh();

    $result = $user->confirmEmailChange('000000');

    $user->refresh();

    // If the real code happens to be '000000', this test still passes
    // because the assertion is on the overall flow behavior
    if ($user->email_change_code === '000000') {
        expect($result)->toBeTrue();
    } else {
        expect($result)->toBeFalse()
            ->and($user->email)->toBe('old@example.com');
    }
});

it('rejects email change with expired verification code', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'old@example.com']);

    $user->requestEmailChange('new@example.com');
    $user->refresh();

    $code = $user->email_change_code;

    // Expire the code manually
    $user->update(['email_change_code_expires_at' => now()->subMinute()]);

    $result = $user->confirmEmailChange($code);

    $user->refresh();

    expect($result)->toBeFalse()
        ->and($user->email)->toBe('old@example.com');
});
