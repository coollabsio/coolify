<?php

use App\Jobs\VolumeCloneJob;
use App\Livewire\Project\Shared\ResourceOperations;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

const LEGACY_CLONE_SERVICE_COMPOSE = <<<'YAML'
services:
  app:
    image: ghost:5
    environment:
      - SERVICE_FQDN_APP_2368
      - DB_PASSWORD=${SERVICE_PASSWORD_DB}
      - DB_USER=${SERVICE_USER_DB}
      - PLAIN=hello
    volumes:
      - 'app-data:/var/lib/ghost/content'
      - './config.json:/etc/app/config.json'
    depends_on:
      - db
  db:
    image: postgres:16-alpine
    environment:
      - POSTGRES_PASSWORD=${SERVICE_PASSWORD_DB}
      - POSTGRES_USER=${SERVICE_USER_DB}
    volumes:
      - 'db-data:/var/lib/postgresql/data'
YAML;

const LEGACY_CLONE_APPLICATION_COMPOSE = <<<'YAML'
services:
  web:
    image: nginx:alpine
    environment:
      - SERVICE_FQDN_WEB_80
      - SECRET=${SERVICE_PASSWORD_WEB}
      - PLAIN=hello
    volumes:
      - 'web-data:/usr/share/nginx/html'
      - './nginx.conf:/etc/nginx/nginx.conf'
  db:
    image: postgres:16-alpine
    environment:
      - POSTGRES_PASSWORD=${SERVICE_PASSWORD_WEB}
    volumes:
      - 'db-data:/var/lib/postgresql/data'
YAML;

beforeEach(function () {
    $this->withoutVite();
    Server::flushIdentityMap();
    InstanceSettings::unguarded(fn () => InstanceSettings::firstOrCreate(['id' => 0], ['is_api_enabled' => true]));
    config(['constants.ssh.mux_enabled' => false]);
    Bus::fake();
    Queue::fake();
    Process::fake(['*' => Process::result(output: '')]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $this->project->id]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function legacyCloneService(string $version): Service
{
    $service = Service::factory()->create([
        'docker_compose_raw' => LEGACY_CLONE_SERVICE_COMPOSE,
        'environment_id' => test()->environment->id,
        'server_id' => test()->server->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ]);
    $service->forceFill(['compose_parsing_version' => $version])->saveQuietly();
    $service->parse();

    return $service->refresh();
}

function legacyCloneApplication(string $version, string $compose = LEGACY_CLONE_APPLICATION_COMPOSE): Application
{
    $application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $compose,
        'docker_compose_location' => '/docker-compose.yaml',
        'redirect' => 'both',
        'environment_id' => test()->environment->id,
        'destination_id' => test()->destination->id,
        'destination_type' => test()->destination->getMorphClass(),
    ]);
    $application->forceFill(['compose_parsing_version' => $version])->saveQuietly();
    $application->parse();

    return $application->refresh();
}

/**
 * The VolumeCloneJob instances of the dispatched clone chains, also the chained ones.
 *
 * @return Collection<int, VolumeCloneJob>
 */
function dispatchedVolumeClones(): Collection
{
    return Bus::dispatched(VolumeCloneJob::class)->concat(Bus::dispatched(CallQueuedClosure::class))
        ->flatMap(fn ($job) => [$job, ...array_map(unserialize(...), $job->chained ?? [])])
        ->filter(fn ($job) => $job instanceof VolumeCloneJob)
        ->values();
}

/**
 * The parts of a parsed service that depend on the parser version, with the uuid replaced.
 */
function serviceParserShape(Service $service): array
{
    $service->refresh();
    $children = $service->applications()->get()->concat($service->databases()->get());

    return [
        'version' => $service->compose_parsing_version,
        'children' => $children->map(fn ($child) => class_basename($child).':'.$child->name)->sort()->values()->all(),
        'volumes' => $children->flatMap(fn ($child) => $child->persistentStorages()->get()
            ->map(fn ($volume) => $child->name.':'.str_replace($service->uuid, '{uuid}', $volume->name).'=>'.$volume->mount_path))
            ->sort()->values()->all(),
        'files' => $children->flatMap(fn ($child) => $child->fileStorages()->get()
            ->map(fn ($file) => $child->name.':'.str_replace($service->uuid, '{uuid}', $file->fs_path).'=>'.$file->mount_path))
            ->sort()->values()->all(),
        'env_keys' => $service->environment_variables()->pluck('key')->sort()->values()->all(),
        'compose' => composeParserShape((string) $service->docker_compose, $service->uuid),
    ];
}

/**
 * The volume sources, networks and top-level volumes of a generated compose file, with the uuid replaced.
 */
function composeParserShape(string $compose, string $uuid): array
{
    $yaml = Yaml::parse(str_replace($uuid, 'UUID', $compose)) ?? [];

    return [
        'services' => collect(data_get($yaml, 'services', []))->map(fn ($service) => [
            'volumes' => collect(data_get($service, 'volumes', []))->map(fn ($volume) => is_array($volume) ? data_get($volume, 'source').':'.data_get($volume, 'target') : $volume)->all(),
            'networks' => array_keys((array) data_get($service, 'networks', [])),
        ])->all(),
        'volumes' => data_get($yaml, 'volumes', []),
        'networks' => data_get($yaml, 'networks', []),
    ];
}

function applicationParserShape(Application $application): array
{
    $application->refresh();

    return [
        'version' => $application->compose_parsing_version,
        'volumes' => $application->persistentStorages()->get()
            ->map(fn ($volume) => str_replace($application->uuid, '{uuid}', $volume->name).'=>'.$volume->mount_path)
            ->sort()->values()->all(),
        'files' => $application->fileStorages()->get()
            ->map(fn ($file) => str_replace($application->uuid, '{uuid}', $file->fs_path).'=>'.$file->mount_path)
            ->sort()->values()->all(),
        'env_keys' => $application->environment_variables()->pluck('key')->sort()->values()->all(),
        'compose' => composeParserShape((string) $application->docker_compose, $application->uuid),
    ];
}

test('cloning a legacy service gives the latest parser output', function (string $version) {
    $source = legacyCloneService($version);
    $fresh = legacyCloneService('5');

    $clone = clone_service($source, test()->destination, ['environment_id' => test()->environment->id]);

    expect(serviceParserShape($clone))->toEqual(serviceParserShape($fresh));
})->with(['2', '3', '4']);

test('cloning a legacy application gives the latest parser output', function (string $version) {
    $source = legacyCloneApplication($version);
    $fresh = legacyCloneApplication('5');

    $clone = clone_application($source, test()->destination, ['environment_id' => test()->environment->id]);
    $clone->parse();

    expect(applicationParserShape($clone))->toEqual(applicationParserShape($fresh));
})->with(['1', '2', '3', '4']);

test('cloning a legacy service keeps generated passwords and copies volume data', function (string $version) {
    $source = legacyCloneService($version);
    $sourcePassword = $source->environment_variables()->where('key', 'SERVICE_PASSWORD_DB')->value('value');

    $clone = clone_service($source, test()->destination, ['environment_id' => test()->environment->id], cloneVolumeData: true);

    expect($clone->environment_variables()->where('key', 'SERVICE_PASSWORD_DB')->value('value'))->toBe($sourcePassword);

    $pairs = dispatchedVolumeClones()->map(fn ($job) => (fn () => str_replace([$source->uuid, $clone->uuid], ['{src}', '{dst}'], $this->sourceVolume.'=>'.$this->targetVolume))->call($job))->sort()->values()->all();

    expect($pairs)->toBe(['{src}_app-data=>{dst}_app-data', '{src}_db-data=>{dst}_db-data']);
})->with(['2', '3', '4']);

test('cloning a legacy application keeps generated passwords and copies volume data', function (string $version, array $sourceVolumes) {
    $source = legacyCloneApplication($version);
    $sourcePassword = $source->environment_variables()->where('key', 'SERVICE_PASSWORD_WEB')->value('value');

    $clone = clone_application($source, test()->destination, ['environment_id' => test()->environment->id], cloneVolumeData: true);

    expect($clone->environment_variables()->where('key', 'SERVICE_PASSWORD_WEB')->value('value'))->toBe($sourcePassword);

    $pairs = dispatchedVolumeClones()
        ->map(fn ($job) => (fn () => str_replace([$source->uuid, $clone->uuid], ['{src}', '{dst}'], $this->sourceVolume.'=>'.$this->targetVolume))->call($job))
        ->sort()->values()->all();

    expect($pairs)->toBe($sourceVolumes);
})->with([
    'v1' => ['1', ['db-data=>{dst}_db-data', 'web-data=>{dst}_web-data']],
    'v2' => ['2', ['{src}-db-data=>{dst}_db-data', '{src}-web-data=>{dst}_web-data']],
    'v3' => ['3', ['{src}_db-data=>{dst}_db-data', '{src}_web-data=>{dst}_web-data']],
    'v4' => ['4', ['{src}_db-data=>{dst}_db-data', '{src}_web-data=>{dst}_web-data']],
]);

test('the clone of a legacy application mounts the copied volumes on its first parse', function (string $version) {
    $source = legacyCloneApplication($version);

    $clone = clone_application($source, test()->destination, ['environment_id' => test()->environment->id], cloneVolumeData: true);
    $clone->parse();

    expect($clone->persistentStorages()->pluck('mount_path', 'name')->sortKeys()->all())->toBe([
        "{$clone->uuid}_db-data" => '/var/lib/postgresql/data',
        "{$clone->uuid}_web-data" => '/usr/share/nginx/html',
    ]);
})->with(['1', '2']);

test('cloning a legacy application does not copy into an external volume', function () {
    $application = legacyCloneApplication('2', <<<'YAML'
        services:
          web:
            image: nginx:alpine
            volumes:
              - 'shared:/shared'
              - 'web-data:/data'
        volumes:
          shared:
            external: true
        YAML);

    $clone = clone_application($application, test()->destination, ['environment_id' => test()->environment->id], cloneVolumeData: true);

    expect(dispatchedVolumeClones()->map(fn ($job) => (fn () => $this->sourceVolume.'=>'.$this->targetVolume)->call($job))->all())
        ->toBe(["{$application->uuid}-web-data=>{$clone->uuid}_web-data"]);
});

test('migrateParser clones a legacy resource with its volume data into the latest parser', function (string $type) {
    $source = $type === 'service' ? legacyCloneService('2') : legacyCloneApplication('2');

    Livewire::test(ResourceOperations::class, ['resource' => $source])
        ->call('migrateParser')
        ->assertHasNoErrors()
        ->assertRedirect();

    $clone = $source::query()->whereKeyNot($source->id)->sole();
    expect($clone->compose_parsing_version)->toBe('5')
        ->and($clone->environment_id)->toBe($source->environment_id)
        ->and($clone->destination_id)->toBe($source->destination_id)
        ->and($source->refresh()->compose_parsing_version)->toBe('2');

    expect(dispatchedVolumeClones())->toHaveCount(2);
})->with(['application', 'service']);

test('migrateParser does nothing for a resource on the latest parser', function () {
    $source = legacyCloneApplication('5');

    Livewire::test(ResourceOperations::class, ['resource' => $source])->call('migrateParser');

    expect(Application::query()->count())->toBe(1);
    expect(dispatchedVolumeClones())->toBeEmpty();
});

test('migrateParser is forbidden for a user of another team', function () {
    $source = legacyCloneApplication('2');
    $otherUser = User::factory()->create();
    $otherTeam = Team::factory()->create();
    $otherUser->teams()->attach($otherTeam, ['role' => 'owner']);
    test()->actingAs($otherUser);
    session(['currentTeam' => $otherTeam]);

    Livewire::test(ResourceOperations::class, ['resource' => $source])->call('migrateParser');

    expect(Application::query()->count())->toBe(1);
    expect(dispatchedVolumeClones())->toBeEmpty();
});

test('only resources on an older Compose parser use a legacy parser', function () {
    expect(legacyCloneApplication('2')->usesLegacyComposeParser())->toBeTrue()
        ->and(legacyCloneApplication('5')->usesLegacyComposeParser())->toBeFalse()
        ->and(legacyCloneService('4')->usesLegacyComposeParser())->toBeTrue()
        ->and(legacyCloneService('5')->usesLegacyComposeParser())->toBeFalse();

    $dockerfileApplication = legacyCloneApplication('2');
    $dockerfileApplication->forceFill(['build_pack' => 'dockerfile'])->saveQuietly();
    expect($dockerfileApplication->usesLegacyComposeParser())->toBeFalse();
});
