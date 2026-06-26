<?php

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::updateOrCreate(['id' => 0]);
    RateLimiter::clear('login');

    $this->user = User::factory()->create([
        'email' => 'test@example.com',
        'password' => bcrypt('password'),
    ]);
});

test('login is rate limited after 5 failed attempts from same IP', function () {
    $email = 'test@example.com';

    // First 5 attempts should be accepted (302 redirect back with error, not 429)
    for ($i = 1; $i <= 5; $i++) {
        $response = $this->post('/login', [
            'email' => $email,
            'password' => 'wrong-password',
        ]);

        expect($response->status())->toBe(302, "Attempt {$i} should redirect (302), got {$response->status()}");
    }

    // 6th attempt from same IP should be throttled
    $response = $this->post('/login', [
        'email' => $email,
        'password' => 'wrong-password',
    ]);

    expect($response->status())->toBe(429, 'Expected 429 Too Many Requests after exceeding rate limit');
});

test('rate limit is scoped per email and IP combination', function () {
    // Exhaust rate limit for first email
    for ($i = 1; $i <= 5; $i++) {
        $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ]);
    }

    // Different email from same IP should still work (different composite key)
    $response = $this->post('/login', [
        'email' => 'other@example.com',
        'password' => 'wrong-password',
    ]);

    expect($response->status())->toBe(302, 'Different email should not be rate limited');
});

test('successful login is still possible within rate limit', function () {
    $response = $this->post('/login', [
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    $response->assertRedirect();
    expect($response->status())->not->toBe(429);
});
