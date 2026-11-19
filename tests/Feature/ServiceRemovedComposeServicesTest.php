<?php

use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const COMPOSE_WITH_EXTRA_PARTS = <<<'YAML'
services:
  web:
    image: nginx:alpine
  test-orphan:
    image: alpine:latest
  db:
    image: postgres:16
  old-db:
    image: postgres:16
YAML;

const COMPOSE_WITHOUT_EXTRA_PARTS = <<<'YAML'
services:
  web:
    image: nginx:alpine
  db:
    image: postgres:16
YAML;

function makeServiceWithCompose(string $compose): Service
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);

    return Service::factory()->create([
        'server_id' => $server->id,
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => $compose,
    ]);
}

function reparseWithCompose(Service $service, string $compose): Service
{
    $service->update(['docker_compose_raw' => $compose]);
    $service = $service->fresh();
    $service->parse();

    return $service->fresh();
}

test('parts removed from the compose file are marked and keep their settings', function () {
    $service = makeServiceWithCompose(COMPOSE_WITH_EXTRA_PARTS);
    $service->parse();
    $service->applications()->where('name', 'test-orphan')->update(['fqdn' => 'https://orphan.example.com']);

    $service = reparseWithCompose($service, COMPOSE_WITHOUT_EXTRA_PARTS);

    expect($service->applications()->where('is_removed_from_compose', true)->pluck('name')->all())->toBe(['test-orphan'])
        ->and($service->databases()->where('is_removed_from_compose', true)->pluck('name')->all())->toBe(['old-db'])
        ->and($service->applications()->where('is_removed_from_compose', false)->pluck('name')->all())->toBe(['web'])
        ->and($service->databases()->where('is_removed_from_compose', false)->pluck('name')->all())->toBe(['db'])
        ->and($service->applications()->where('name', 'test-orphan')->value('fqdn'))->toBe('https://orphan.example.com');
});

test('a part that comes back to the compose file is used again with its settings', function () {
    $service = makeServiceWithCompose(COMPOSE_WITH_EXTRA_PARTS);
    $service->parse();
    $orphan = $service->applications()->where('name', 'test-orphan')->firstOrFail();
    $orphan->update(['fqdn' => 'https://orphan.example.com']);

    $service = reparseWithCompose($service, COMPOSE_WITHOUT_EXTRA_PARTS);
    $service = reparseWithCompose($service, COMPOSE_WITH_EXTRA_PARTS);

    $restored = $service->applications()->where('name', 'test-orphan')->sole();
    expect($restored->id)->toBe($orphan->id)
        ->and($restored->is_removed_from_compose)->toBeFalse()
        ->and($restored->fqdn)->toBe('https://orphan.example.com');
});

test('parts are not marked when the compose file cannot be parsed', function () {
    $service = makeServiceWithCompose(COMPOSE_WITH_EXTRA_PARTS);
    $service->parse();

    $service = reparseWithCompose($service, "services:\n  web:\n    image: [nginx\n");

    expect($service->applications()->where('is_removed_from_compose', true)->count())->toBe(0)
        ->and($service->databases()->where('is_removed_from_compose', true)->count())->toBe(0);
});

test('parts removed from the compose file do not change the service status', function () {
    $service = makeServiceWithCompose(COMPOSE_WITH_EXTRA_PARTS);
    $service->parse();
    $service->applications()->update(['status' => 'running:healthy']);
    $service->databases()->update(['status' => 'running:healthy']);

    $service = reparseWithCompose($service, COMPOSE_WITHOUT_EXTRA_PARTS);
    $service->applications()->where('name', 'test-orphan')->update(['status' => 'exited']);
    $service->databases()->where('name', 'old-db')->update(['status' => 'exited']);

    expect($service->fresh()->status)->toBe('running:healthy');
});
