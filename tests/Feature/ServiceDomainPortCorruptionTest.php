<?php

/**
 * Feature regression for #8798 / #8980: saving a service domain that includes a
 * required port must not corrupt SERVICE_URL values.
 *
 * EditDomain saves fqdn then calls updateCompose() (Spatie-based) and parse().
 * updateCompose is the path that writes SERVICE_URL_* from the saved FQDN and is
 * fully exerciseable under SQLite; getFqdnWithoutPort coverage lives in unit tests.
 */
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Url\Url;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $this->server->id]);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
});

test('updateCompose keeps valid SERVICE_URL values when domain includes required port', function () {
    $template = <<<'YAML'
services:
  gitlab:
    image: gitlab/gitlab-ce:latest
    environment:
      - SERVICE_URL_GITLAB_80
      - EXTERNAL_URL=$SERVICE_URL_GITLAB
YAML;

    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => $template,
        'compose_parsing_version' => '5',
    ]);

    $serviceApp = ServiceApplication::create([
        'name' => 'gitlab',
        'service_id' => $service->id,
        'image' => 'gitlab/gitlab-ce:latest',
        'fqdn' => 'http://git.example.com:80',
    ]);

    // EditDomain::submit calls updateCompose after saving fqdn
    updateCompose($serviceApp);

    $serviceApp->refresh();

    expect($serviceApp->fqdn)->toBe('http://git.example.com:80')
        ->and($serviceApp->fqdn)->not->toContain('//:80')
        ->and(fn () => Url::fromString($serviceApp->fqdn))->not->toThrow(Throwable::class);

    $baseUrl = $service->environment_variables()->where('key', 'SERVICE_URL_GITLAB')->first();
    $portUrl = $service->environment_variables()->where('key', 'SERVICE_URL_GITLAB_80')->first();

    expect($baseUrl)->not->toBeNull()
        ->and($portUrl)->not->toBeNull()
        ->and($baseUrl->value)->toBe('http://git.example.com')
        ->and($portUrl->value)->toBe('http://git.example.com:80')
        ->and(fn () => Url::fromString($baseUrl->value))->not->toThrow(Throwable::class)
        ->and(fn () => Url::fromString($portUrl->value))->not->toThrow(Throwable::class);
});

test('getFqdnWithoutPort used by serviceParser strip is safe for ported service domains', function () {
    // Mirrors serviceParser when $savedService->fqdn already has a port
    $savedFqdn = 'http://git.example.com:80';
    $firstFqdn = trim((string) str($savedFqdn)->explode(',')->first());
    $url = getFqdnWithoutPort($firstFqdn);
    $port = '80';
    $urlWithPort = "$url:$port";

    expect($url)->toBe('http://git.example.com')
        ->and($urlWithPort)->toBe('http://git.example.com:80')
        ->and($urlWithPort)->not->toBe('http://git.example.com/:80')
        ->and(fn () => Url::fromString($urlWithPort))->not->toThrow(Throwable::class);
});
