<?php

use App\Jobs\VolumeCloneJob;
use App\Livewire\Project\CloneMe;
use App\Livewire\Project\Shared\ResourceOperations;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\LocalPersistentVolume;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const CLONE_SERVICE_COMPOSE = <<<'YAML'
services:
  web:
    image: nginx:alpine
    volumes:
      - 'web-data:/usr/share/nginx/html'
  db:
    image: postgres:16-alpine
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
    $server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $this->destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = $this->project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $this->project->id]);

    $this->service = Service::factory()->create([
        'docker_compose_raw' => CLONE_SERVICE_COMPOSE,
        'environment_id' => $this->environment->id,
        'server_id' => $server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $this->service->parse();

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

/**
 * The source and target volume names of the VolumeCloneJobs in the dispatched chain, in chain order.
 *
 * @return array<int, array{0: string, 1: string}>
 */
function chainedServiceVolumeClones(): array
{
    $first = Bus::dispatched(CallQueuedClosure::class)->first();
    expect($first)->not->toBeNull('No volume clone chain was dispatched.');

    return collect($first->chained)
        ->map(fn (string $job) => unserialize($job))
        ->filter(fn ($job) => $job instanceof VolumeCloneJob)
        ->map(fn (VolumeCloneJob $job) => (fn () => [$this->sourceVolume, $this->targetVolume])->call($job))
        ->values()
        ->all();
}

/**
 * @return array<int, string>
 */
function serviceVolumeNames(Service $service): array
{
    $resources = $service->applications()->get()->concat($service->databases()->get());

    return LocalPersistentVolume::query()
        ->where(function ($query) use ($resources) {
            foreach ($resources as $resource) {
                $query->orWhere(fn ($query) => $query
                    ->where('resource_type', $resource->getMorphClass())
                    ->where('resource_id', $resource->id));
            }
        })
        ->orderBy('name')
        ->pluck('name')
        ->all();
}

/**
 * @return array<int, array{0: string, 1: string}>
 */
function expectedServiceVolumeClones(Service $source, Service $clone): array
{
    return collect(['web-data', 'db-data'])
        ->map(fn (string $volume) => ["{$source->uuid}_{$volume}", "{$clone->uuid}_{$volume}"])
        ->all();
}

test('cloning a service from resource operations copies the data of its named volumes', function () {
    Livewire::test(ResourceOperations::class, ['resource' => $this->service])
        ->set('cloneVolumeData', true)
        ->call('cloneTo', $this->destination->uuid)
        ->assertHasNoErrors();

    $clone = Service::query()->whereKeyNot($this->service->id)->sole();

    expect(chainedServiceVolumeClones())->toEqualCanonicalizing(expectedServiceVolumeClones($this->service, $clone))
        ->and(serviceVolumeNames($clone))->toBe(["{$clone->uuid}_db-data", "{$clone->uuid}_web-data"]);
});

test('cloning an environment copies the data of the named volumes of its services', function () {
    Livewire::test(CloneMe::class, [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
    ])
        ->set('selectedDestination', $this->destination->uuid)
        ->set('newName', 'cloned-env')
        ->set('cloneVolumeData', true)
        ->call('clone', 'environment')
        ->assertHasNoErrors();

    $clone = Service::query()->whereKeyNot($this->service->id)->sole();

    expect(chainedServiceVolumeClones())->toEqualCanonicalizing(expectedServiceVolumeClones($this->service, $clone))
        ->and(serviceVolumeNames($clone))->toBe(["{$clone->uuid}_db-data", "{$clone->uuid}_web-data"]);
});

test('cloning a service through the API copies the data of its named volumes', function () {
    $plainTextToken = Str::random(40);
    $token = $this->user->tokens()->create([
        'name' => 'clone-service-test',
        'token' => hash('sha256', $plainTextToken),
        'abilities' => ['*'],
        'team_id' => $this->team->id,
    ]);

    auth()->forgetGuards();

    $this->withHeaders(['Authorization' => 'Bearer '.$token->getKey().'|'.$plainTextToken])
        ->postJson("/api/v1/services/{$this->service->uuid}/clone", [
            'destination_uuid' => $this->destination->uuid,
            'clone_volumes' => true,
        ])
        ->assertCreated();

    $clone = Service::query()->whereKeyNot($this->service->id)->sole();

    expect(chainedServiceVolumeClones())->toEqualCanonicalizing(expectedServiceVolumeClones($this->service, $clone));
});

test('cloning a service without volume data dispatches no volume copy', function () {
    Livewire::test(ResourceOperations::class, ['resource' => $this->service])
        ->call('cloneTo', $this->destination->uuid)
        ->assertHasNoErrors();

    $clone = Service::query()->whereKeyNot($this->service->id)->sole();

    Bus::assertNotDispatched(CallQueuedClosure::class);
    Bus::assertNotDispatched(VolumeCloneJob::class);
    expect(serviceVolumeNames($clone))->toHaveCount(2);
});
