<?php

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::query()->forceCreate([
        'id' => 0,
        'is_registration_enabled' => true,
    ]);

    User::factory()->create(['id' => 0, 'email' => 'root@example.com']);
});

it('rate limits repeated registration attempts from the same ip', function () {
    foreach (range(1, 3) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post('/register', [
                'name' => "Attack User {$attempt}",
                'email' => "attacker{$attempt}@example.com",
                'password' => 'Password1!@',
                'password_confirmation' => 'Password1!@',
            ])
            ->assertRedirect();

        auth()->logout();
        $this->flushSession();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->post('/register', [
            'name' => 'Blocked User',
            'email' => 'blocked@example.com',
            'password' => 'Password1!@',
            'password_confirmation' => 'Password1!@',
        ])
        ->assertTooManyRequests();
});

it('rate limits dotted plus-address variants of the same email identity across ips', function () {
    $emails = [
        'ke.vinmcfadden+one@gmail.com',
        'kevin.mcfadden+two@gmail.com',
        'k.e.v.i.n.m.c.f.a.d.d.e.n+three@gmail.com',
    ];

    foreach ($emails as $index => $email) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.($index + 10)])
            ->post('/register', [
                'name' => "Attack User {$index}",
                'email' => $email,
                'password' => 'Password1!@',
                'password_confirmation' => 'Password1!@',
            ])
            ->assertRedirect();

        auth()->logout();
        $this->flushSession();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
        ->post('/register', [
            'name' => 'Blocked User',
            'email' => 'k.evin.mcfadden+four@gmail.com',
            'password' => 'Password1!@',
            'password_confirmation' => 'Password1!@',
        ])
        ->assertTooManyRequests();
});

it('keeps distinct dotted and plus-addressed mailboxes in separate rate limit buckets on ordinary domains', function () {
    $registrationIpKey = 'registration:ip:'.sha1('127.0.0.1');
    $emails = [
        'john.smith@example.com',
        'johnsmith@example.com',
        'johnsmith+one@example.com',
        'johnsmith+two@example.com',
    ];

    foreach ($emails as $index => $email) {
        $this->post('/register', [
            'name' => "Distinct User {$index}",
            'email' => $email,
            'password' => 'Password1!@',
            'password_confirmation' => 'Password1!@',
        ])
            ->assertRedirect();

        auth()->logout();
        $this->flushSession();
        RateLimiter::clear($registrationIpKey);
    }
});
