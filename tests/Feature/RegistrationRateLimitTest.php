<?php

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
        'ke.vinmcfadden+one@btinternet.com',
        'kevin.mcfadden+two@btinternet.com',
        'k.e.v.i.n.m.c.f.a.d.d.e.n+three@btinternet.com',
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
            'email' => 'k.evin.mcfadden+four@btinternet.com',
            'password' => 'Password1!@',
            'password_confirmation' => 'Password1!@',
        ])
        ->assertTooManyRequests();
});
