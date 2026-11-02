<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('constants.coolify.self_hosted', true);

    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);

    session(['currentTeam' => $team]);
    $this->token = $user->createToken('read', ['read'])->plainTextToken;
});

it('reports success false when the API is disabled', function () {
    Once::flush();
    // Postgres returns a real boolean; SQLite would return 0 for the uncast column.
    InstanceSettings::get()->is_api_enabled = false;

    $this->withToken($this->token)
        ->getJson('/api/v1/version')
        ->assertForbidden()
        ->assertExactJson(['success' => false, 'message' => 'API is disabled.']);
});

it('reports success false when the client IP is not in the allow-list', function () {
    InstanceSettings::query()->where('id', 0)->update(['allowed_ips' => '192.0.2.10']);
    Once::flush();

    $this->withToken($this->token)
        ->getJson('/api/v1/version')
        ->assertForbidden()
        ->assertExactJson(['success' => false, 'message' => 'You are not allowed to access the API.']);
});

it('reports success false when the client IP is not in the allow-list on the IP-only route group', function () {
    InstanceSettings::query()->where('id', 0)->update(['allowed_ips' => '192.0.2.10']);
    Once::flush();

    $this->withToken($this->token)
        ->getJson('/api/v1/disable')
        ->assertForbidden()
        ->assertExactJson(['success' => false, 'message' => 'You are not allowed to access the API.']);
});
