<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'app.maintenance.driver' => 'file',
        'cache.default' => 'array',
        'session.driver' => 'array',
    ]);

    InstanceSettings::query()->whereKey(0)->delete();
    $settings = new InstanceSettings(['is_api_enabled' => true]);
    $settings->id = 0;
    $settings->save();
    Once::flush();

    $this->rootTeam = Team::factory()->create(['id' => 0]);
});

function instanceEmailToken(User $user, Team $team, string $role, array $abilities): string
{
    $team->members()->attach($user->id, ['role' => $role]);
    session(['currentTeam' => $team]);

    return $user->createToken('instance-email-test', $abilities)->plainTextToken;
}

function instanceEmailHeaders(string $token): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];
}

test('root team owners can get instance email settings', function () {
    InstanceSettings::findOrFail(0)->update([
        'smtp_enabled' => true,
        'smtp_ehlo_domain' => 'coolify.example.com',
    ]);
    $token = instanceEmailToken(User::factory()->create(), $this->rootTeam, 'owner', ['read']);

    $this->withHeaders(instanceEmailHeaders($token))
        ->getJson('/api/v1/settings/email')
        ->assertSuccessful()
        ->assertJsonPath('smtp_enabled', true)
        ->assertJsonPath('smtp_ehlo_domain', 'coolify.example.com')
        ->assertJsonMissingPath('smtp_password');
});

test('root team admins can update instance email settings', function () {
    $token = instanceEmailToken(User::factory()->create(), $this->rootTeam, 'admin', ['write:sensitive']);

    $this->withHeaders(instanceEmailHeaders($token))
        ->patchJson('/api/v1/settings/email', [
            'smtp_enabled' => true,
            'smtp_from_address' => 'alerts@example.com',
            'smtp_from_name' => 'Coolify',
            'smtp_host' => 'smtp.example.com',
            'smtp_port' => 587,
            'smtp_encryption' => 'starttls',
            'smtp_username' => 'coolify',
            'smtp_password' => 'secret',
            'smtp_timeout' => 10,
            'smtp_ehlo_domain' => 'coolify.example.com',
        ])
        ->assertSuccessful()
        ->assertJsonPath('smtp_ehlo_domain', 'coolify.example.com');

    $settings = InstanceSettings::findOrFail(0);
    expect($settings->smtp_enabled)->toBeTrue()
        ->and($settings->smtp_host)->toBe('smtp.example.com')
        ->and($settings->smtp_ehlo_domain)->toBe('coolify.example.com');
});

test('instance email settings reject non-root teams', function () {
    $team = Team::factory()->create();
    $token = instanceEmailToken(User::factory()->create(), $team, 'owner', ['read', 'write']);

    $this->withHeaders(instanceEmailHeaders($token))
        ->getJson('/api/v1/settings/email')
        ->assertForbidden();

    $this->withHeaders(instanceEmailHeaders($token))
        ->patchJson('/api/v1/settings/email', ['smtp_enabled' => true])
        ->assertForbidden();
});

test('instance email settings reject root team members', function () {
    $token = instanceEmailToken(User::factory()->create(), $this->rootTeam, 'member', ['read']);

    $this->withHeaders(instanceEmailHeaders($token))
        ->getJson('/api/v1/settings/email')
        ->assertForbidden();
});

test('instance email settings validate the smtp ehlo domain', function () {
    $token = instanceEmailToken(User::factory()->create(), $this->rootTeam, 'owner', ['write:sensitive']);

    $this->withHeaders(instanceEmailHeaders($token))
        ->patchJson('/api/v1/settings/email', ['smtp_ehlo_domain' => 'not a hostname'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('smtp_ehlo_domain');
});

test('updating instance email settings requires write sensitive', function () {
    $token = instanceEmailToken(User::factory()->create(), $this->rootTeam, 'owner', ['write']);

    $this->withHeaders(instanceEmailHeaders($token))
        ->patchJson('/api/v1/settings/email', ['smtp_enabled' => true])
        ->assertForbidden();
});

test('root admins cannot use a token issued for another team', function () {
    $user = User::factory()->create();
    $this->rootTeam->members()->attach($user->id, ['role' => 'owner']);
    $team = Team::factory()->create();
    $token = instanceEmailToken($user, $team, 'owner', ['read', 'write:sensitive']);

    $this->withHeaders(instanceEmailHeaders($token))
        ->getJson('/api/v1/settings/email')
        ->assertForbidden();
});

test('read sensitive exposes instance email secrets to root team admins', function () {
    InstanceSettings::findOrFail(0)->update(['smtp_password' => 'secret']);
    $token = instanceEmailToken(User::factory()->create(), $this->rootTeam, 'admin', ['read', 'read:sensitive']);

    $this->withHeaders(instanceEmailHeaders($token))
        ->getJson('/api/v1/settings/email')
        ->assertSuccessful()
        ->assertJsonPath('smtp_password', 'secret');
});
