<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::create(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->member = User::factory()->create();
    $this->admin = User::factory()->create();

    $this->team->members()->attach($this->member->id, ['role' => 'member']);
    $this->team->members()->attach($this->admin->id, ['role' => 'admin']);

    session(['currentTeam' => $this->team]);
});

function apiRequest($test, string $token, string $method = 'get', string $url = '/api/v1/version')
{
    return $test->withHeaders([
        'Authorization' => 'Bearer '.$token,
        'Content-Type' => 'application/json',
    ])->{$method.'Json'}($url);
}

describe('member with legacy elevated token is rejected', function () {
    test('member with legacy write token gets 403 with descriptive message', function () {
        $token = $this->member->createToken('legacy-write', ['read', 'write']);

        $response = apiRequest($this, $token->plainTextToken);

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'message' => 'This API token has permissions (write) that exceed your current role as a team member. Members are restricted to read-only API access. Please revoke this token and create a new one with only read permissions.',
        ]);
    });

    test('member with legacy deploy token gets 403', function () {
        $token = $this->member->createToken('legacy-deploy', ['read', 'deploy']);

        $response = apiRequest($this, $token->plainTextToken);

        $response->assertStatus(403);
        $response->assertSee('deploy');
        $response->assertSee('revoke this token');
    });

    test('member with legacy root token gets 403', function () {
        $token = $this->member->createToken('legacy-root', ['root']);

        $response = apiRequest($this, $token->plainTextToken);

        $response->assertStatus(403);
        $response->assertSee('root');
    });

    test('member with legacy read:sensitive token gets 403', function () {
        $token = $this->member->createToken('legacy-sensitive', ['read', 'read:sensitive']);

        $response = apiRequest($this, $token->plainTextToken);

        $response->assertStatus(403);
        $response->assertSee('read:sensitive');
    });

    test('member with legacy write:sensitive token gets 403', function () {
        $token = $this->member->createToken('legacy-ws', ['read', 'write:sensitive']);

        $response = apiRequest($this, $token->plainTextToken);

        $response->assertStatus(403);
        $response->assertSee('write:sensitive');
    });

    test('member with multiple disallowed abilities lists them all', function () {
        $token = $this->member->createToken('legacy-multi', ['read', 'write', 'deploy', 'read:sensitive']);

        $response = apiRequest($this, $token->plainTextToken);

        $response->assertStatus(403);
        $json = $response->json();
        expect($json['message'])->toContain('write');
        expect($json['message'])->toContain('deploy');
        expect($json['message'])->toContain('read:sensitive');
    });
});

describe('member with read-only token passes through', function () {
    test('member with read token can access read endpoints', function () {
        $token = $this->member->createToken('read-only', ['read']);

        $response = apiRequest($this, $token->plainTextToken);

        $response->assertStatus(200);
    });
});

describe('admin with elevated token passes through', function () {
    test('admin with write token is not blocked', function () {
        $token = $this->admin->createToken('admin-write', ['read', 'write']);

        $response = apiRequest($this, $token->plainTextToken);

        $response->assertStatus(200);
    });

    test('admin with root token is not blocked', function () {
        $token = $this->admin->createToken('admin-root', ['root']);

        $response = apiRequest($this, $token->plainTextToken);

        $response->assertStatus(200);
    });

    test('admin with deploy token is not blocked', function () {
        $token = $this->admin->createToken('admin-deploy', ['read', 'deploy']);

        $response = apiRequest($this, $token->plainTextToken);

        $response->assertStatus(200);
    });
});
