<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'fqdn-field-api-test-'.Str::random(6),
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);
    $this->bearerToken = $token->getKey().'|'.$plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

test('PATCH with fqdn points the caller to the domains field', function () {
    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'fqdn' => 'https://app.example.com',
    ]);

    $this->withToken($this->bearerToken)
        ->patchJson("/api/v1/applications/{$application->uuid}", [
            'fqdn' => 'https://new.example.com',
            'unknown_field' => 'value',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.fqdn', ['Use the domains field to set application URLs.'])
        ->assertJsonPath('errors.unknown_field', ['This field is not allowed.']);

    expect($application->fresh()->fqdn)->toBe('https://app.example.com');
});

test('POST create with fqdn points the caller to the domains field', function () {
    $this->withToken($this->bearerToken)
        ->postJson('/api/v1/applications/dockerimage', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'docker_registry_image_name' => 'nginx',
            'ports_exposes' => '80',
            'fqdn' => 'https://new.example.com',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.fqdn', ['Use the domains field to set application URLs.']);
});
