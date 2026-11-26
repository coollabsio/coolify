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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

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
    $this->destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = $project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $project->id]);
});

/**
 * Parses a Compose file with one short-syntax volume as a Service or a Compose Application, twice like a second save.
 *
 * @param  array<string, string>  $variables  Environment variables that the resource sets before the parse
 * @return array{volume: string, owner: Model}
 */
function parseShortVolumeWithVariables(string $kind, string $volume, array $variables = []): array
{
    $attributes = [
        'docker_compose_raw' => "services:\n  probe:\n    image: alpine:3.22\n    volumes:\n      - '{$volume}'\n",
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ];
    $resource = $kind === 'service'
        ? Service::factory()->create([...$attributes, 'server_id' => test()->destination->server_id])->fresh()
        : Application::factory()->create([...$attributes, 'build_pack' => 'dockercompose'])->fresh();
    foreach ($variables as $key => $value) {
        $resource->environment_variables()->create(['key' => $key, 'value' => $value]);
    }
    $parser = $kind === 'service' ? 'serviceParser' : 'applicationParser';
    $parser($resource);
    $parsed = $parser($resource->fresh())->toArray();

    return [
        'volume' => $parsed['services']['probe']['volumes'][0],
        'owner' => $kind === 'service' ? $resource->applications()->firstOrFail() : $resource,
    ];
}

it('splits a short-syntax volume at the colon after a variable in the middle of the host path', function () {
    $result = parseDockerVolumeString('/volumes/app/${STAGE:-default}/data:/data:ro');

    expect($result['source']->value())->toBe('/volumes/app/${STAGE:-default}/data')
        ->and($result['target']->value())->toBe('/data')
        ->and($result['mode']->value())->toBe('ro');
});

it('keeps a variable in the middle of a short-syntax host path for Docker Compose', function (string $kind) {
    $result = parseShortVolumeWithVariables($kind, '/volumes/app/${STAGE:-default}/data:/data');

    expect($result['volume'])->toBe('/volumes/app/${STAGE:-default}/data:/data')
        ->and($result['owner']->fileStorages()->pluck('fs_path')->all())->toBe(['/volumes/app/${STAGE:-default}/data']);
})->with(['service', 'application']);

it('leaves the variables of a short-syntax host path to Docker Compose', function (string $kind, string $volume, string $expected) {
    $result = parseShortVolumeWithVariables($kind, $volume, ['DATA' => '/media/data', 'STAGE' => 'production']);

    expect($result['volume'])->toBe("{$expected}:/fixture")
        ->and($result['owner']->fileStorages()->pluck('fs_path')->all())->toBe([$expected])
        ->and($result['owner']->persistentStorages()->count())->toBe(0);
})->with(['service', 'application'])->with([
    'variable before the path' => ['${DATA}/wireguard:/fixture', '${DATA}/wireguard'],
    'variable with a host path default' => ['${DATA:-/srv/data}:/fixture', '${DATA:-/srv/data}'],
    'variable with a volume name default and a host path value' => ['${DATA:-wireguard}:/fixture', '/media/data'],
    'variable in the middle of the path' => ['/volumes/app/${STAGE:-default}/data:/fixture', '/volumes/app/${STAGE:-default}/data'],
]);

it('keeps the name of a named volume when the value of its variable is not a host path', function (string $kind) {
    $result = parseShortVolumeWithVariables($kind, '${DATA:-wireguard}:/fixture', ['DATA' => 'other-volume']);

    expect($result['volume'])->toEndWith('_wireguard:/fixture')
        ->and($result['owner']->fileStorages()->count())->toBe(0);
})->with(['service', 'application']);

it('rejects a short-syntax host path with a variable that has no value', function (string $kind) {
    parseShortVolumeWithVariables($kind, '/volumes/${STAGE}/data:/fixture');
})->with(['service', 'application'])->throws(Exception::class, 'uses the variable STAGE, which has no value');

it('rejects an unsafe value for a variable in a short-syntax host path', function (string $kind) {
    parseShortVolumeWithVariables($kind, '${DATA}/wireguard:/fixture', ['DATA' => '/opt/x;touch /tmp/pwned']);
})->with(['service', 'application'])->throws(Exception::class, "forbidden character ';'");

it('keeps the named volume of an older version for a host path with a variable until the user deletes it', function (string $kind) {
    $result = parseShortVolumeWithVariables($kind, '${DATA}/wireguard:/fixture', ['DATA' => '/media/data']);
    $resource = $kind === 'service' ? $result['owner']->service : $result['owner'];
    // v4.7.6 made a named volume from the host path. v4.7.7 saved a file storage for it, as the parse above did.
    $oldVolume = $result['owner']->persistentStorages()->create([
        'name' => $resource->uuid.'_'.Str::slug('${DATA}/wireguard', '-'),
        'mount_path' => '/fixture',
    ]);
    $parse = fn (): string => ($kind === 'service' ? serviceParser($resource->fresh()) : applicationParser($resource->fresh()))
        ->toArray()['services']['probe']['volumes'][0];

    expect($parse())->toBe("{$oldVolume->name}:/fixture")
        ->and($result['owner']->fileStorages()->count())->toBe(0)
        ->and($oldVolume->fresh()->replacedComposeHostPath())->toBe('/media/data/wireguard')
        ->and($oldVolume->fresh()->isDeclaredInCompose())->toBeFalse();

    $oldVolume->delete();

    expect($parse())->toBe('${DATA}/wireguard:/fixture')
        ->and($result['owner']->fileStorages()->pluck('fs_path')->all())->toBe(['${DATA}/wireguard']);
})->with(['service', 'application']);
