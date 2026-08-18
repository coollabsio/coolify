<?php

use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.env', 'local');

    $this->team = Team::factory()->create();

    $this->privateKey = PrivateKey::create([
        'name' => 'Test Key',
        'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----',
        'team_id' => $this->team->id,
    ]);

    Server::factory()->create([
        'name' => 'Test Server',
        'ip' => 'coolify-testing-host',
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
});

// --- POST /terminal/auth ---

it('denies unauthenticated users on POST /terminal/auth', function () {
    $this->postJson('/terminal/auth')
        ->assertStatus(401);
});

it('denies non-admin team members on POST /terminal/auth', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);

    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    $this->postJson('/terminal/auth')
        ->assertStatus(403);
});

it('allows team owners on POST /terminal/auth', function () {
    $owner = User::factory()->create();
    $owner->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($owner);
    session(['currentTeam' => $this->team]);

    $this->postJson('/terminal/auth')
        ->assertStatus(200)
        ->assertJson(['authenticated' => true]);
});

it('allows team admins on POST /terminal/auth', function () {
    $admin = User::factory()->create();
    $admin->teams()->attach($this->team, ['role' => 'admin']);

    $this->actingAs($admin);
    session(['currentTeam' => $this->team]);

    $this->postJson('/terminal/auth')
        ->assertStatus(200)
        ->assertJson(['authenticated' => true]);
});

// --- POST /terminal/auth/ips ---

it('denies unauthenticated users on POST /terminal/auth/ips', function () {
    $this->postJson('/terminal/auth/ips')
        ->assertStatus(401);
});

it('denies non-admin team members on POST /terminal/auth/ips', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);

    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    $this->postJson('/terminal/auth/ips')
        ->assertStatus(403);
});

it('allows team owners on POST /terminal/auth/ips', function () {
    $owner = User::factory()->create();
    $owner->teams()->attach($this->team, ['role' => 'owner']);

    $this->actingAs($owner);
    session(['currentTeam' => $this->team]);

    $this->postJson('/terminal/auth/ips')
        ->assertStatus(200)
        ->assertJsonStructure(['ipAddresses']);
});

it('allows team admins on POST /terminal/auth/ips', function () {
    $admin = User::factory()->create();
    $admin->teams()->attach($this->team, ['role' => 'admin']);

    $this->actingAs($admin);
    session(['currentTeam' => $this->team]);

    $this->postJson('/terminal/auth/ips')
        ->assertStatus(200)
        ->assertJsonStructure(['ipAddresses']);
});
