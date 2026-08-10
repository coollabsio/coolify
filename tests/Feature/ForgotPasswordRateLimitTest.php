<?php

use App\Models\InstanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::query()->forceCreate(['id' => 0]);
});

it('rate limits repeated forgot password attempts from the same ip', function () {
    foreach (range(1, 3) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
            ->post('/forgot-password', [
                'email' => "user{$attempt}@example.com",
            ])
            ->assertSessionHasNoErrors();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
        ->post('/forgot-password', [
            'email' => 'blocked@example.com',
        ])
        ->assertTooManyRequests();
});

it('rate limits dotted plus-address forgot password variants of the same email identity across ips', function () {
    $emails = [
        'ke.vinmcfadden+one@gmail.com',
        'kevin.mcfadden+two@gmail.com',
        'k.e.v.i.n.m.c.f.a.d.d.e.n+three@gmail.com',
    ];

    foreach ($emails as $index => $email) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.($index + 30)])
            ->post('/forgot-password', [
                'email' => $email,
            ])
            ->assertSessionHasNoErrors();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99'])
        ->post('/forgot-password', [
            'email' => 'k.evin.mcfadden+four@gmail.com',
        ])
        ->assertTooManyRequests();
});

it('keeps distinct dotted and plus-addressed mailboxes in separate forgot password buckets on ordinary domains', function () {
    $emails = [
        'john.smith@example.com',
        'johnsmith@example.com',
        'johnsmith+one@example.com',
        'johnsmith+two@example.com',
    ];

    foreach ($emails as $index => $email) {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.($index + 120)])
            ->post('/forgot-password', [
                'email' => $email,
            ])
            ->assertSessionHasNoErrors();
    }
});
