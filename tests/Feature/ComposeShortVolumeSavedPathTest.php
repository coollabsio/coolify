<?php

use App\Livewire\Project\Service\FileStorage;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Before v4.7.6, a short-syntax host path such as `${DATA:-./data}:/data` always used the default. It now uses the
 * value of the variable. A saved storage keeps its path, so the data of an existing resource does not move; the
 * user switches to the path of the variable with a button.
 */
beforeEach(function () {
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0]));
    config(['constants.ssh.mux_enabled' => false]);
    Bus::fake();
    Queue::fake();
    Process::fake(['*' => Process::result(output: '')]);

    $this->team = Team::factory()->create();
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
});

/**
 * @return array{resource: Application|Service, parse: Closure(): string, storage: Closure(): LocalFileVolume}
 */
function savedPathResource(string $kind): array
{
    $attributes = [
        'docker_compose_raw' => "services:\n  probe:\n    image: alpine:3.22\n    volumes:\n      - '\${DATA_DIR:-./data}:/data'\n",
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ];
    $resource = $kind === 'service'
        ? Service::factory()->create([...$attributes, 'server_id' => test()->destination->server_id])->fresh()
        : Application::factory()->create([...$attributes, 'build_pack' => 'dockercompose'])->fresh();
    $parser = $kind === 'service' ? 'serviceParser' : 'applicationParser';
    $owner = fn () => $kind === 'service' ? $resource->fresh()->applications()->firstOrFail() : $resource->fresh();

    return [
        'resource' => $resource,
        'parse' => function () use ($parser, $resource) {
            $fresh = $resource->fresh();
            $parsed = $parser($fresh)->toArray();
            test()->warnings = $fresh->composeVolumeWarnings();

            return $parsed['services']['probe']['volumes'][0];
        },
        'storage' => fn () => $owner()->fileStorages()->where('mount_path', '/data')->firstOrFail(),
    ];
}

it('keeps the saved host path of an existing storage when its variable gets a value', function (string $kind) {
    ['resource' => $resource, 'parse' => $parse, 'storage' => $storage] = savedPathResource($kind);
    $savedPath = $resource->workdir().'/data';
    expect($parse())->toBe("{$savedPath}:/data");

    $resource->environment_variables()->create(['key' => 'DATA_DIR', 'value' => '/mnt/custom']);

    expect($parse())->toBe("{$savedPath}:/data")
        ->and($storage()->fs_path)->toBe($savedPath)
        ->and($storage()->variable_fs_path)->toBe('/mnt/custom')
        ->and(implode("\n", test()->warnings))->toContain($savedPath)->toContain('/mnt/custom');
})->with(['application', 'service']);

it('uses the value of the variable for a new storage', function (string $kind) {
    ['resource' => $resource, 'parse' => $parse, 'storage' => $storage] = savedPathResource($kind);
    $resource->environment_variables()->create(['key' => 'DATA_DIR', 'value' => '/mnt/custom']);

    expect($parse())->toBe('/mnt/custom:/data')
        ->and($storage()->fs_path)->toBe('/mnt/custom')
        ->and($storage()->variable_fs_path)->toBeNull();
})->with(['application', 'service']);

it('switches a saved storage to the path of its variable', function (string $kind) {
    ['resource' => $resource, 'parse' => $parse, 'storage' => $storage] = savedPathResource($kind);
    $parse();
    $resource->environment_variables()->create(['key' => 'DATA_DIR', 'value' => '/mnt/custom']);
    $parse();
    $owner = User::factory()->create();
    $this->team->members()->attach($owner, ['role' => 'owner']);
    $this->actingAs($owner);
    session(['currentTeam' => $this->team]);

    Livewire::test(FileStorage::class, ['fileStorage' => $storage()])
        ->call('useVariablePath')
        ->assertHasNoErrors();

    expect($storage()->fs_path)->toBe('/mnt/custom')
        ->and($storage()->variable_fs_path)->toBeNull()
        ->and($parse())->toBe('/mnt/custom:/data')
        ->and($storage()->variable_fs_path)->toBeNull();
})->with(['application', 'service']);

it('does not let a member switch the path of a storage', function () {
    ['resource' => $resource, 'parse' => $parse, 'storage' => $storage] = savedPathResource('application');
    $parse();
    $resource->environment_variables()->create(['key' => 'DATA_DIR', 'value' => '/mnt/custom']);
    $parse();
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);

    Livewire::test(FileStorage::class, ['fileStorage' => $storage()])
        ->call('useVariablePath')
        ->assertForbidden();

    expect($storage()->fs_path)->toBe($resource->workdir().'/data');
});
