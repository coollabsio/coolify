<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['is_api_enabled' => true]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $this->bearerToken = $this->user->createToken('team-update-validation', ['write'])->plainTextToken;
});

test('PATCH /team returns JSON validation errors without an Accept header', function () {
    $response = $this->call(
        'PATCH',
        '/api/v1/team',
        server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->bearerToken,
            'CONTENT_TYPE' => 'application/json',
        ],
        content: json_encode(['is_build_server_fallback_enabled' => 'invalid']),
    );

    $response->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed.')
        ->assertJsonStructure(['errors' => ['is_build_server_fallback_enabled']]);
    expect($this->team->fresh()->is_build_server_fallback_enabled)->toBeTrue();
});

test('PATCH /team rejects unknown fields', function () {
    $this->withToken($this->bearerToken)
        ->patchJson('/api/v1/team', [
            'is_build_server_fallback_enabled' => false,
            'name' => 'renamed',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'This field is not allowed.');

    expect($this->team->fresh())
        ->is_build_server_fallback_enabled->toBeTrue()
        ->name->not->toBe('renamed');
});
