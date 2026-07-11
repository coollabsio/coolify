<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use phpseclib3\Crypt\EC;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create([
        'project_id' => $this->project->id,
    ]);

    $ecKey = EC::createKey('Ed25519');
    $privateKeyContent = $ecKey->toString('OpenSSH');

    $privateKey = PrivateKey::create([
        'name' => 'test-key',
        'private_key' => $privateKeyContent,
        'team_id' => $this->team->id,
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);

    ServerSetting::create([
        'server_id' => $this->server->id,
        'wildcard_domain' => 'http://127.0.0.1.sslip.io',
    ]);

    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'test-network-'.fake()->uuid(),
    ]);
});

test('applicationParser populates docker_compose_domains for KEY-based SERVICE_FQDN variables', function () {
    $dockerCompose = <<<'YAML'
services:
  backend:
    image: myapp/backend:latest
    environment:
      - SERVICE_FQDN_BACKEND_8000=${BACKEND_URL}
  frontend:
    image: myapp/frontend:latest
    environment:
      - SERVICE_FQDN_FRONTEND=${FRONTEND_URL}
YAML;

    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $dockerCompose,
        'fqdn' => null,
        'docker_compose_domains' => null,
    ]);

    applicationParser($application);

    $application->refresh();

    $domains = json_decode($application->docker_compose_domains, true);

    expect($domains)->not->toBeNull()
        ->and($domains)->toBeArray()
        ->and($domains)->toHaveKey('backend')
        ->and($domains['backend'])->toHaveKey('domain')
        ->and($domains['backend']['domain'])->toStartWith('http://')
        ->and($domains['backend']['domain'])->not->toBeEmpty();
});

test('applicationParser populates docker_compose_domains for KEY-based SERVICE_FQDN without port', function () {
    $dockerCompose = <<<'YAML'
services:
  frontend:
    image: myapp/frontend:latest
    environment:
      - SERVICE_FQDN_FRONTEND=${FRONTEND_URL}
YAML;

    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $dockerCompose,
        'fqdn' => null,
        'docker_compose_domains' => null,
    ]);

    applicationParser($application);

    $application->refresh();

    $domains = json_decode($application->docker_compose_domains, true);

    expect($domains)->not->toBeNull()
        ->and($domains)->toHaveKey('frontend')
        ->and($domains['frontend'])->toHaveKey('domain');
});

test('applicationParser does not populate docker_compose_domains for non-dockercompose build_pack', function () {
    $dockerCompose = <<<'YAML'
services:
  backend:
    image: myapp/backend:latest
    environment:
      - SERVICE_FQDN_BACKEND_8000=${BACKEND_URL}
YAML;

    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockerfile',
        'docker_compose_raw' => $dockerCompose,
        'fqdn' => null,
        'docker_compose_domains' => null,
    ]);

    applicationParser($application);

    $application->refresh();

    // For non-dockercompose, docker_compose_domains should remain empty
    $domains = json_decode($application->docker_compose_domains, true);
    expect($domains)->toBeNull();
});

test('applicationParser populates docker_compose_domains for KEY-based SERVICE_URL variables', function () {
    $dockerCompose = <<<'YAML'
services:
  frontend:
    image: myapp/frontend:latest
    environment:
      - SERVICE_URL_FRONTEND=/ui
YAML;

    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $dockerCompose,
        'fqdn' => null,
        'docker_compose_domains' => null,
    ]);

    applicationParser($application);

    $application->refresh();

    $domains = json_decode($application->docker_compose_domains, true);

    expect($domains)->not->toBeNull()
        ->and($domains)->toHaveKey('frontend')
        ->and($domains['frontend']['domain'])->toStartWith('http://')
        ->and($domains['frontend']['domain'])->toEndWith('/ui');
});

test('applicationParser preserves existing docker_compose_domains entries', function () {
    $dockerCompose = <<<'YAML'
services:
  backend:
    image: myapp/backend:latest
    environment:
      - SERVICE_FQDN_BACKEND_8000=${BACKEND_URL}
  frontend:
    image: myapp/frontend:latest
    environment:
      - SERVICE_URL_FRONTEND=/ui
YAML;

    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $dockerCompose,
        'fqdn' => null,
        'docker_compose_domains' => json_encode([
            'frontend' => ['domain' => 'https://existing.example.com'],
        ]),
    ]);

    applicationParser($application);

    $application->refresh();

    $domains = json_decode($application->docker_compose_domains, true);

    expect($domains['frontend']['domain'])->toBe('https://existing.example.com')
        ->and($domains)->toHaveKey('backend')
        ->and($domains['backend']['domain'])->toStartWith('http://');
});

test('applicationParser handles other docker compose domain shapes without regressions', function () {
    $createApplication = function (string $dockerCompose, ?string $dockerComposeDomains = null): Application {
        return Application::factory()->create([
            'environment_id' => $this->environment->id,
            'destination_id' => $this->destination->id,
            'destination_type' => StandaloneDocker::class,
            'build_pack' => 'dockercompose',
            'docker_compose_raw' => $dockerCompose,
            'fqdn' => null,
            'docker_compose_domains' => $dockerComposeDomains,
        ]);
    };

    $valueBasedCompose = <<<'YAML'
services:
  backend:
    image: myapp/backend:latest
  frontend:
    image: myapp/frontend:latest
    environment:
      API_URL: ${SERVICE_URL_BACKEND}
YAML;

    $valueBasedApplication = $createApplication($valueBasedCompose);
    applicationParser($valueBasedApplication);
    $valueBasedApplication->refresh();
    $valueBasedDomains = json_decode($valueBasedApplication->docker_compose_domains, true);

    expect($valueBasedDomains)->toHaveKey('backend')
        ->and($valueBasedDomains['backend']['domain'])->toStartWith('http://')
        ->and($valueBasedDomains)->not->toHaveKey('frontend');

    $mapStyleCompose = <<<'YAML'
services:
  frontend:
    image: myapp/frontend:latest
    environment:
      SERVICE_URL_FRONTEND: /ui
YAML;

    $mapStyleApplication = $createApplication($mapStyleCompose);
    applicationParser($mapStyleApplication);
    $mapStyleApplication->refresh();
    $mapStyleDomains = json_decode($mapStyleApplication->docker_compose_domains, true);

    expect($mapStyleDomains)->toHaveKey('frontend')
        ->and($mapStyleDomains['frontend']['domain'])->toEndWith('/ui');

    $missingServiceCompose = <<<'YAML'
services:
  worker:
    image: myapp/worker:latest
    environment:
      SERVICE_URL_API: /api
YAML;

    $missingServiceApplication = $createApplication($missingServiceCompose);
    applicationParser($missingServiceApplication);
    $missingServiceApplication->refresh();

    expect(json_decode($missingServiceApplication->docker_compose_domains, true))->toBeNull();

    $plainCompose = <<<'YAML'
services:
  worker:
    image: myapp/worker:latest
    environment:
      FOO: bar
YAML;

    $plainApplication = $createApplication($plainCompose);
    applicationParser($plainApplication);
    $plainApplication->refresh();

    expect(json_decode($plainApplication->docker_compose_domains, true))->toBeNull();
});
