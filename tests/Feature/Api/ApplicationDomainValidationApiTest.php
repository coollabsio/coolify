<?php

use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.store' => 'array']);
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);

    $this->token = $this->user->createToken('test-token', ['*']);
    $this->bearerToken = $this->token->plainTextToken;

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);

    StandaloneDocker::withoutEvents(function () {
        $this->destination = $this->server->standaloneDockers()->firstOrCreate(
            ['network' => 'coolify'],
            ['uuid' => (string) new Cuid2, 'name' => 'test-docker']
        );
    });

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);

    // Project boot event auto-creates a 'production' environment
    $this->environment = $this->project->environments()->first();

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
});

it('rejects wildcard domains on application updates regardless of proxy configuration', function (string $proxyType) {
    $this->server->proxy = ['type' => $proxyType];
    $this->server->save();
    $originalDomain = $this->application->fqdn;

    $this->withToken($this->bearerToken)
        ->patchJson("/api/v1/applications/{$this->application->uuid}", [
            'domains' => 'https://*.example.com',
            'force_domain_override' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed.')
        ->assertJsonPath('errors', fn (array $errors): bool => collect($errors)->flatten()->contains('Invalid URL: https://*.example.com'));

    expect($this->application->fresh()->fqdn)->toBe($originalDomain);
})->with([ProxyTypes::NONE->value, ProxyTypes::TRAEFIK->value]);

it('rejects wildcard domains on application creation', function (string $proxyType) {
    $this->server->proxy = ['type' => $proxyType];
    $this->server->save();
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $count = Application::count();

    $this->withToken($this->bearerToken)
        ->postJson('/api/v1/applications/dockerimage', [
            'project_uuid' => $this->project->uuid,
            'environment_uuid' => $this->environment->uuid,
            'server_uuid' => $this->server->uuid,
            'docker_registry_image_name' => 'nginx',
            'ports_exposes' => '80',
            'domains' => 'https://*.example.com',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Validation failed.')
        ->assertJsonPath('errors', fn (array $errors): bool => collect($errors)->flatten()->contains('Invalid URL: https://*.example.com'));

    expect(Application::count())->toBe($count);
})->with([ProxyTypes::NONE->value, ProxyTypes::TRAEFIK->value]);
