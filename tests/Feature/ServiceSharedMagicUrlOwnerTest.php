<?php

/**
 * Regression for #11924: when two compose services declare the same
 * SERVICE_URL_<NAME>_<PORT> (old Twenty template: twenty + worker), the
 * variable must follow the domain of the <NAME> application, not the domain
 * of whichever service the parser processes last.
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

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'compose_parsing_version' => '5',
        'docker_compose_raw' => <<<'YAML'
services:
  twenty:
    image: twentycrm/twenty:v1.15
    environment:
      - SERVICE_URL_TWENTY_3000
  worker:
    image: twentycrm/twenty:v1.15
    environment:
      - SERVICE_URL_TWENTY_3000
YAML,
    ]);
});

it('keeps SERVICE_URL_TWENTY on the twenty domain when worker declares the same variable', function () {
    $this->service->parse();

    $twenty = $this->service->applications()->where('name', 'twenty')->first();
    $twenty->update(['fqdn' => 'https://crm.example.com']);

    $this->service->fresh()->parse();

    $env = fn (string $key) => $this->service->environment_variables()->where('key', $key)->value('value');

    expect($env('SERVICE_URL_TWENTY'))->toBe('https://crm.example.com')
        ->and($env('SERVICE_FQDN_TWENTY'))->toBe('crm.example.com')
        ->and($env('SERVICE_URL_TWENTY_3000'))->toBe('https://crm.example.com:3000')
        ->and($this->service->applications()->where('name', 'worker')->value('fqdn'))->toBeNull();
});

it('does not overwrite SERVICE_URL_TWENTY when the worker domain is saved', function () {
    $this->service->parse();
    $this->service->applications()->where('name', 'twenty')->first()->update(['fqdn' => 'https://crm.example.com']);
    $this->service->fresh()->parse();

    // Installs parsed before the fix keep a generated domain on worker.
    $worker = $this->service->applications()->where('name', 'worker')->first();
    $worker->update(['fqdn' => 'http://twenty-stale.10.0.1.1.sslip.io']);

    updateCompose($worker->fresh());

    expect($this->service->environment_variables()->where('key', 'SERVICE_URL_TWENTY')->value('value'))
        ->toBe('https://crm.example.com');
});

it('keeps path routing when services declare the same variable with another path or port', function () {
    $this->service->update(['docker_compose_raw' => <<<'YAML'
services:
  appwrite:
    image: appwrite/appwrite:1.7
    environment:
      - SERVICE_URL_APPWRITE=/v1
  appwrite-console:
    image: appwrite/console:6
    environment:
      - SERVICE_URL_APPWRITE_3000=/
  appwrite-realtime:
    image: appwrite/appwrite:1.7
    environment:
      - SERVICE_URL_APPWRITE=/v1/realtime
YAML]);

    $this->service->parse();

    $domains = $this->service->applications()->pluck('fqdn', 'name');
    $host = str($domains['appwrite'])->before('/v1')->value();

    expect($domains['appwrite'])->toEndWith('.sslip.io/v1')
        ->and($domains['appwrite-console'])->toBe($host)
        ->and($domains['appwrite-realtime'])->toBe("{$host}/v1/realtime");
});

it('does not treat a build argument as a copy of the named application declaration', function () {
    $this->service->update(['docker_compose_raw' => <<<'YAML'
services:
  twenty:
    image: twentycrm/twenty:v1.15
    environment:
      - SERVICE_URL_TWENTY_3000
  web:
    image: nginx:alpine
    build:
      context: .
      args:
        - SERVICE_URL_TWENTY_3000=/admin
YAML]);

    $this->service->parse();

    expect($this->service->applications()->where('name', 'web')->value('fqdn'))->toEndWith('/admin');
});

it('does not treat a service as a copy of a duplicate row with the same name', function () {
    $this->service->update(['docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx:alpine
    environment:
      - SERVICE_URL_APP_80
YAML]);
    ServiceApplication::create(['name' => 'app', 'service_id' => $this->service->id, 'image' => 'nginx:alpine']);
    ServiceApplication::create(['name' => 'app', 'service_id' => $this->service->id, 'image' => 'nginx:alpine', 'is_migrated' => true, 'fqdn' => 'https://app.example.com']);

    $this->service->parse();

    expect($this->service->environment_variables()->where('key', 'SERVICE_URL_APP')->value('value'))
        ->toBe('https://app.example.com');
});

it('fixes SERVICE_URL_TWENTY for installs where worker already has a generated domain', function () {
    $this->service->parse();
    $this->service->applications()->where('name', 'twenty')->update(['fqdn' => 'https://crm.example.com']);
    $this->service->applications()->where('name', 'worker')->update(['fqdn' => 'http://twenty-stale.10.0.1.1.sslip.io']);
    $this->service->environment_variables()->where('key', 'SERVICE_URL_TWENTY')->first()->update(['value' => 'http://twenty-stale.10.0.1.1.sslip.io']);

    $this->service->fresh()->parse();

    expect($this->service->environment_variables()->where('key', 'SERVICE_URL_TWENTY')->value('value'))->toBe('https://crm.example.com')
        ->and($this->service->applications()->where('name', 'worker')->value('fqdn'))->toBe('http://twenty-stale.10.0.1.1.sslip.io');
});

it('treats SERVICE_FQDN_ with the same name, port and value as a copy of SERVICE_URL_', function () {
    $this->service->update(['docker_compose_raw' => <<<'YAML'
services:
  twenty:
    image: twentycrm/twenty:v1.15
    environment:
      - SERVICE_URL_TWENTY_3000
  worker:
    image: twentycrm/twenty:v1.15
    environment:
      - SERVICE_FQDN_TWENTY_3000
YAML]);

    $this->service->parse();
    $this->service->applications()->where('name', 'twenty')->first()->update(['fqdn' => 'https://crm.example.com']);
    $this->service->fresh()->parse();

    expect($this->service->environment_variables()->where('key', 'SERVICE_URL_TWENTY')->value('value'))->toBe('https://crm.example.com')
        ->and($this->service->environment_variables()->where('key', 'SERVICE_FQDN_TWENTY_3000')->value('value'))->toBe('crm.example.com:3000')
        ->and($this->service->applications()->where('name', 'worker')->value('fqdn'))->toBeNull();
});
