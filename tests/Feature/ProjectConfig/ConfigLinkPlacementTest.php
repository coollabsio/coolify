<?php

use App\Actions\Application\StopApplicationOneServer;
use App\Actions\Database\StopDatabase;
use App\Enums\ConfigRevisionStatus;
use App\Jobs\DeleteResourceJob;
use App\Livewire\Project\EnvironmentConfiguration;
use App\Models\Application;
use App\Models\EnvironmentConfigLink;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\User;
use App\Services\ProjectConfig\ConfigApplier;
use App\Services\ProjectConfig\ConfigExporter;
use App\Services\ProjectConfig\ConfigLinks;
use App\Services\ProjectConfig\ConfigRevisions;
use App\Services\ProjectConfig\HeldDeployments;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake([DeleteResourceJob::class]);
    $this->stops = [];
    $stops = &$this->stops;
    app()->instance(HeldDeployments::class, new class($stops) extends HeldDeployments
    {
        public function __construct(private array &$stops) {}

        public function deploy(Application $application, ?string $commit): void {}

        public function restart(Model $resource, bool $created): void {}

        public function stopOnOldDestination(Model $resource, Model $oldDestination): void
        {
            $this->stops[] = [$resource->config_key, $oldDestination->id];
        }
    });
    $this->fx = projectConfigFixture();
    $this->fx['project']->update(['name' => 'Some other name']);
    $this->link = EnvironmentConfigLink::factory()->create([
        'environment_id' => $this->fx['environment']->id, 'read_server_id' => $this->fx['server']->id,
    ])->fresh();
});

function placementFile(array $resources, array $extra = []): string
{
    return json_encode(['version' => 1, 'project' => 'acme', 'resources' => $resources, 'environments' => ['production' => ['branch' => 'main']]] + $extra);
}

function placementApp(array $overrides = []): array
{
    return array_replace([
        'type' => 'application', 'build' => ['pack' => 'dockerfile'], 'ports' => ['exposes' => '3000'],
        'source' => ['repository' => 'https://github.com/acme/api', 'branch' => 'main'],
    ], $overrides);
}

function placementApply(string $file)
{
    $revision = app(ConfigRevisions::class)->record(test()->link->fresh(), $file, 'api');
    app(ConfigApplier::class)->apply($revision);

    return $revision->fresh();
}

it('ignores the project name and places resources without a server on the server of the link', function () {
    $revision = placementApply(placementFile(['api' => placementApp(), 'db' => ['type' => 'postgresql']]));

    expect($revision->status)->toBe(ConfigRevisionStatus::Applied)
        ->and(Application::where('config_key', 'api')->sole()->destination->server_id)->toBe($this->fx['server']->id)
        ->and(StandalonePostgresql::where('config_key', 'db')->sole()->destination->server_id)->toBe($this->fx['server']->id);

    $this->fx['server']->update(['name' => 'renamed']);
    $again = app(ConfigRevisions::class)->record($this->link->fresh(), placementFile(['api' => placementApp(), 'db' => ['type' => 'postgresql']]), 'api');

    expect($again->plan['changes'])->toBe([])
        ->and(app(ConfigExporter::class)->export($this->fx['environment']->fresh())['resources']['api'])->not->toHaveKey('server');
});

it('uses a server that the file names, and the destination of the link on a server with several', function () {
    $other = Server::factory()->create(['team_id' => $this->fx['team']->id, 'name' => 'prod-2']);
    $second = StandaloneDocker::forceCreate(['name' => 'b', 'network' => 'net-b', 'server_id' => $this->fx['server']->id, 'uuid' => new_public_id()]);
    $this->link->update(['destination_type' => $second->getMorphClass(), 'destination_id' => $second->id]);

    placementApply(placementFile(['api' => placementApp(), 'worker' => placementApp(['server' => 'prod-2'])]));

    expect(Application::where('config_key', 'api')->sole()->destination_id)->toBe($second->id)
        ->and(Application::where('config_key', 'worker')->sole()->destination->server_id)->toBe($other->id);

    $export = app(ConfigExporter::class)->export($this->fx['environment']->fresh())['resources'];
    expect($export['api'])->not->toHaveKeys(['server', 'destination'])
        ->and($export['worker']['server'])->toBe('prod-2');
});

it('fails the plan when neither the file nor the link names a server', function () {
    $this->link->update(['read_server_id' => null]);

    $revision = app(ConfigRevisions::class)->record($this->link->fresh(), placementFile(['api' => placementApp()]), 'api');

    expect($revision->status)->toBe(ConfigRevisionStatus::Failed)
        ->and($revision->error)->toContain('resources.api.server');
});

it('manages external Docker volumes', function () {
    placementApply(placementFile(['api' => placementApp(['storages' => ['shared' => ['mount' => '/data', 'volume_name' => 'shared', 'external' => true]]])]));

    $application = Application::where('config_key', 'api')->sole();
    expect($application->persistentStorages()->sole()->is_external)->toBeTrue()
        ->and(app(ConfigExporter::class)->export($this->fx['environment']->fresh())['resources']['api']['storages']['shared']['external'])->toBeTrue();
});

it('links through the API with a server and a destination of that server only', function () {
    $token = $this->fx['user']->createToken('all', ['*'], teamId: $this->fx['team']->id)->plainTextToken;
    $other = Server::factory()->create(['team_id' => $this->fx['team']->id, 'name' => 'prod-2']);
    $foreignDestination = StandaloneDocker::where('server_id', $other->id)->first();
    $headers = ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    $body = ['project' => 'shop', 'environment' => 'production', 'mode' => 'api', 'server_uuid' => $this->fx['server']->uuid];

    $this->withHeaders($headers)->postJson('/api/v1/config/links', $body + ['destination_uuid' => $foreignDestination->uuid])->assertStatus(422);
    app('auth')->forgetGuards();
    $response = $this->withHeaders($headers)->postJson('/api/v1/config/links', $body + ['destination_uuid' => $this->fx['destination']->uuid])->assertCreated();

    expect($response->json('server_uuid'))->toBe($this->fx['server']->uuid)
        ->and($response->json('destination_uuid'))->toBe($this->fx['destination']->uuid);
});

it('moves resources without a server after the link server changes, only after approval', function () {
    $other = Server::factory()->create(['team_id' => $this->fx['team']->id, 'name' => 'prod-2']);
    $file = placementFile(['api' => placementApp(), 'db' => ['type' => 'postgresql'], 'pinned' => placementApp(['server' => 'prod-1'])]);
    placementApply($file);
    $oldDestination = $this->fx['destination']->id;

    app(ConfigLinks::class)->changeServer($this->link->fresh(), $this->fx['user'], ['server_uuid' => $other->uuid]);
    $revision = $this->link->revisions()->latest('id')->first();

    expect($revision->status)->toBe(ConfigRevisionStatus::WaitingApproval)
        ->and(collect($revision->plan['changes'])->pluck('key')->unique()->sort()->values()->all())->toBe(['api', 'db'])
        ->and($revision->plan['warnings'])->toContain('"api" moves to another server or network. Coolify stops it on the old one and starts it on the new one. Volumes and their data stay on the old server.')
        ->and(Application::where('config_key', 'api')->sole()->destination->server_id)->toBe($this->fx['server']->id);

    app(ConfigLinks::class)->approve($revision, $this->fx['user'], []);

    expect($revision->fresh()->status)->toBe(ConfigRevisionStatus::Applied)
        ->and(Application::where('config_key', 'api')->sole()->destination->server_id)->toBe($other->id)
        ->and(StandalonePostgresql::where('config_key', 'db')->sole()->destination->server_id)->toBe($other->id)
        ->and(Application::where('config_key', 'pinned')->sole()->destination->server_id)->toBe($this->fx['server']->id)
        ->and($this->stops)->toEqualCanonicalizing([['api', $oldDestination], ['db', $oldDestination]]);
});

it('stops a moved database on its old destination, and a moved application on its old server', function () {
    placementApply(placementFile(['api' => placementApp(), 'db' => ['type' => 'postgresql']]));
    $other = Server::factory()->create(['team_id' => $this->fx['team']->id, 'name' => 'prod-2']);
    $database = StandalonePostgresql::where('config_key', 'db')->sole();
    $application = Application::where('config_key', 'api')->sole();
    $old = $this->fx['destination'];
    $database->forceFill(['destination_id' => StandaloneDocker::where('server_id', $other->id)->first()->id])->save();
    StopDatabase::shouldRun()->once()->withArgs(fn ($copy) => $copy->destination->is($old));
    StopApplicationOneServer::shouldRun()->once()->withArgs(fn ($app, $server) => $server->is($this->fx['server']));

    $held = new HeldDeployments;
    $held->stopOnOldDestination($database, $old);
    $held->stopOnOldDestination($application, $old);
});

it('lets only a team admin change the server on the page', function () {
    placementApply(placementFile(['api' => placementApp()]));
    $other = Server::factory()->create(['team_id' => $this->fx['team']->id, 'name' => 'prod-2']);
    $destination = StandaloneDocker::where('server_id', $other->id)->first();
    $params = ['project_uuid' => $this->fx['project']->uuid, 'environment_uuid' => $this->fx['environment']->uuid];
    $member = User::factory()->create();
    $this->fx['team']->members()->attach($member->id, ['role' => 'member']);

    $this->actingAs($member);
    Livewire::test(EnvironmentConfiguration::class, $params)->set('serverDestinationUuid', $destination->uuid)->call('changeServer');
    expect($this->link->fresh()->read_server_id)->toBe($this->fx['server']->id);

    $this->actingAs($this->fx['user']);
    Livewire::test(EnvironmentConfiguration::class, $params)
        ->set('serverDestinationUuid', $destination->uuid)
        ->call('previewServerChange')
        ->assertSet('serverError', null)
        ->assertSee('moves to another server')
        ->call('changeServer');

    expect($this->link->fresh()->read_server_id)->toBe($other->id)
        ->and($this->link->revisions()->latest('id')->first()->status)->toBe(ConfigRevisionStatus::WaitingApproval);
});

it('changes the server through the API only with the deploy ability', function () {
    placementApply(placementFile(['api' => placementApp()]));
    $other = Server::factory()->create(['team_id' => $this->fx['team']->id, 'name' => 'prod-2']);
    $writeToken = $this->fx['user']->createToken('w', ['read', 'write'], teamId: $this->fx['team']->id)->plainTextToken;
    $token = $this->fx['user']->createToken('all', ['*'], teamId: $this->fx['team']->id)->plainTextToken;
    $patch = function (string $token, array $body) {
        app('auth')->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])->patchJson("/api/v1/config/links/{$this->link->uuid}", $body);
    };

    $patch($writeToken, ['server_uuid' => $other->uuid])->assertForbidden();
    $patch($token, ['server_uuid' => $other->uuid, 'destination_uuid' => $this->fx['destination']->uuid])->assertStatus(422);
    $patch($token, ['server_uuid' => $other->uuid])->assertOk()->assertJsonPath('server_uuid', $other->uuid);
    $patch($writeToken, ['auto_apply' => false])->assertOk();
});
