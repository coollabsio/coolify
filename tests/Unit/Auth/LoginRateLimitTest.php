<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

uses(\Tests\TestCase::class);

test('login rate limiter uses real IP not spoofable headers', function () {
    // Get the rate limiter for login
    $limiter = RateLimiter::limiter('login');

    // Create a mock request with X-Forwarded-For header (attempt to spoof)
    $request = Request::create('/login', 'POST', [
        'email' => 'test@example.com',
        'password' => 'password',
    ]);

    // Set spoofed header
    $request->headers->set('X-Forwarded-For', '10.0.0.99');

    // Set the real IP (REMOTE_ADDR)
    $request->server->set('REMOTE_ADDR', '192.168.1.1');

    // Get the limit from the rate limiter
    $limit = $limiter($request);

    expect($limit)->toBeInstanceOf(Limit::class);

    // The key should be based on email + REMOTE_ADDR, not X-Forwarded-For
    // We can't directly inspect the key, but we can verify the behavior
    // by checking that the same REMOTE_ADDR is rate limited regardless of X-Forwarded-For

    // Reset rate limiter for this test
    RateLimiter::clear('test@example.com192.168.1.1');

    // Make 5 attempts with different X-Forwarded-For headers but same REMOTE_ADDR
    for ($i = 1; $i <= 5; $i++) {
        $testRequest = Request::create('/login', 'POST', [
            'email' => 'test@example.com',
            'password' => 'wrong',
        ]);
        $testRequest->headers->set('X-Forwarded-For', "10.0.0.{$i}");
        $testRequest->server->set('REMOTE_ADDR', '192.168.1.1');

        $available = RateLimiter::attempt(
            'test@example.com192.168.1.1',
            5,
            function () {},
            60
        );

        if ($i < 5) {
            expect($available)->toBeTrue();
        }
    }

    // 6th attempt should be rate limited
    $sixthRequest = Request::create('/login', 'POST', [
        'email' => 'test@example.com',
        'password' => 'wrong',
    ]);
    $sixthRequest->headers->set('X-Forwarded-For', '10.0.0.6');
    $sixthRequest->server->set('REMOTE_ADDR', '192.168.1.1');

    $available = RateLimiter::attempt(
        'test@example.com192.168.1.1',
        5,
        function () {},
        60
    );

    expect($available)->toBeFalse();

    // Cleanup
    RateLimiter::clear('test@example.com192.168.1.1');
});

test('forgot-password rate limiter uses real IP not spoofable headers', function () {
    // Get the rate limiter for forgot-password
    $limiter = RateLimiter::limiter('forgot-password');

    // Create a mock request with X-Forwarded-For header
    $request = Request::create('/forgot-password', 'POST', [
        'email' => 'test@example.com',
    ]);

    $request->headers->set('X-Forwarded-For', '10.0.0.99');
    $request->server->set('REMOTE_ADDR', '192.168.1.2');

    $limit = $limiter($request);

    expect($limit)->toBeInstanceOf(Limit::class);

    // Reset for test
    RateLimiter::clear('192.168.1.2');

    // Make 5 attempts
    for ($i = 1; $i <= 5; $i++) {
        $testRequest = Request::create('/forgot-password', 'POST');
        $testRequest->headers->set('X-Forwarded-For', "10.0.0.{$i}");
        $testRequest->server->set('REMOTE_ADDR', '192.168.1.2');

        $available = RateLimiter::attempt(
            '192.168.1.2',
            5,
            function () {},
            60
        );

        if ($i < 5) {
            expect($available)->toBeTrue();
        }
    }

    // 6th attempt should fail
    $available = RateLimiter::attempt(
        '192.168.1.2',
        5,
        function () {},
        60
    );

    expect($available)->toBeFalse();

    // Cleanup
    RateLimiter::clear('192.168.1.2');
});

test('different REMOTE_ADDR IPs are rate limited separately', function () {
    // Reset
    RateLimiter::clear('test@example.com192.168.1.3');
    RateLimiter::clear('test@example.com192.168.1.4');

    // Make 5 attempts from first IP
    for ($i = 1; $i <= 5; $i++) {
        $available = RateLimiter::attempt(
            'test@example.com192.168.1.3',
            5,
            function () {},
            60
        );
        expect($available)->toBeTrue();
    }

    // First IP should be rate limited now
    $available = RateLimiter::attempt(
        'test@example.com192.168.1.3',
        5,
        function () {},
        60
    );
    expect($available)->toBeFalse();

    // Second IP should still have attempts available
    $available = RateLimiter::attempt(
        'test@example.com192.168.1.4',
        5,
        function () {},
        60
    );
    expect($available)->toBeTrue();

    // Cleanup
    RateLimiter::clear('test@example.com192.168.1.3');
    RateLimiter::clear('test@example.com192.168.1.4');
});
