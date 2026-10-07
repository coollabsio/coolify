<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    config(['constants.ssh.mux_enabled' => false]);
    Bus::fake();
    Queue::fake();
    Process::fake(['*' => Process::result(output: '')]);

    $team = Team::factory()->create();
    $privateKey = PrivateKey::factory()->create(['team_id' => $team->id]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $this->destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
});

function longBindCompose(string $source, string $options): string
{
    return <<<YAML
services:
  probe:
    image: alpine:3.22
    volumes:
      - type: bind
        source: {$source}
        target: /fixture
{$options}
YAML;
}

const LONG_BIND_STRICT_OPTIONS = <<<'YAML'
        read_only: true
        bind:
          create_host_path: false
YAML;

const LONG_BIND_READ_ONLY_OPTIONS = <<<'YAML'
        read_only: true
YAML;

/**
 * Parses the Compose file as a Service or a Compose Application, twice like a second save.
 *
 * @return array{volume: mixed, storages: Collection, directory: string}
 */
function parseLongBind(string $kind, string $compose): array
{
    if ($kind === 'service') {
        $resource = Service::factory()->create([
            'docker_compose_raw' => $compose,
            'environment_id' => test()->environment->id,
            'server_id' => test()->destination->server_id,
            'destination_id' => test()->destination->id,
            'destination_type' => test()->destination->getMorphClass(),
        ])->fresh();
        serviceParser($resource);
        $parsed = serviceParser($resource->fresh())->toArray();
        $owner = $resource->applications()->firstOrFail();
        $directory = base_configuration_dir().'/services/'.$resource->uuid;
    } else {
        $resource = Application::factory()->create([
            'build_pack' => 'dockercompose',
            'docker_compose_raw' => $compose,
            'environment_id' => test()->environment->id,
            'destination_id' => test()->destination->id,
            'destination_type' => test()->destination->getMorphClass(),
        ])->fresh();
        applicationParser($resource);
        $parsed = applicationParser($resource->fresh())->toArray();
        $owner = $resource;
        $directory = base_configuration_dir().'/applications/'.$resource->uuid;
    }

    return [
        'volume' => $parsed['services']['probe']['volumes'][0],
        'storages' => $owner->fileStorages()->where('mount_path', '/fixture')->get(),
        'directory' => $directory,
    ];
}

it('keeps a non-creating long-form bind and does not manage its host path', function (string $kind) {
    $result = parseLongBind($kind, longBindCompose('/opt/strict-bind-repro/missing-directory', LONG_BIND_STRICT_OPTIONS));

    expect($result['volume'])->toBe([
        'type' => 'bind',
        'source' => '/opt/strict-bind-repro/missing-directory',
        'target' => '/fixture',
        'read_only' => true,
        'bind' => ['create_host_path' => false],
    ])->and($result['storages'])->toBeEmpty();
})->with(['service', 'application']);

it('resolves the relative source of a non-creating long-form bind', function (string $kind) {
    $result = parseLongBind($kind, longBindCompose('./missing', LONG_BIND_STRICT_OPTIONS));

    expect($result['volume']['source'])->toBe($result['directory'].'/missing')
        ->and($result['volume']['bind'])->toBe(['create_host_path' => false])
        ->and($result['storages'])->toBeEmpty();
})->with(['service', 'application']);

it('keeps the options of a long-form bind that Coolify manages', function (string $kind) {
    $result = parseLongBind($kind, longBindCompose('./data', LONG_BIND_READ_ONLY_OPTIONS));

    expect($result['volume'])->toBe([
        'type' => 'bind',
        'source' => $result['directory'].'/data',
        'target' => '/fixture',
        'read_only' => true,
        // Short syntax always created a missing source; Docker Compose before v2.30 does not do that for long syntax.
        'bind' => ['create_host_path' => true],
    ])->and($result['storages']->pluck('fs_path')->all())->toBe([$result['directory'].'/data']);
})->with(['service', 'application']);

it('removes volume keys that Docker Compose rejects from a long-form bind', function (string $kind, string $source) {
    $result = parseLongBind($kind, longBindCompose($source, <<<'YAML'
        mode: "0755"
YAML));

    expect($result['volume'])->not->toHaveKey('mode')
        ->and($result['volume']['bind'])->toBe(['create_host_path' => true]);
})->with(['service', 'application'])->with(['./entrypoint.sh', '/var/run/docker.sock']);

it('removes the storage that an earlier save created when a bind keeps its missing host path', function (string $kind) {
    $first = parseLongBind($kind, longBindCompose('/opt/strict-bind-repro/missing-directory', LONG_BIND_READ_ONLY_OPTIONS));
    expect($first['storages'])->toHaveCount(1);

    $owner = $first['storages']->first()->resource;
    $resource = $kind === 'service' ? $owner->service : $owner;
    $resource->update(['docker_compose_raw' => longBindCompose('/opt/strict-bind-repro/missing-directory', LONG_BIND_STRICT_OPTIONS)]);
    $kind === 'service' ? serviceParser($resource->fresh()) : applicationParser($resource->fresh());

    expect($owner->fileStorages()->where('mount_path', '/fixture')->exists())->toBeFalse();
})->with(['service', 'application']);
