<?php

use App\Actions\Server\DeleteServer;
use App\Livewire\Server\Delete;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    config(['app.maintenance.store' => 'array']);
    InstanceSettings::forceCreate(['id' => 0]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id, 'ip' => '203.0.113.61']);
    $this->server->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    ServiceApplication::create(['service_id' => $this->service->id, 'name' => 'web', 'image' => 'nginx:alpine']);

    $this->commands = collect();
    Process::fake(function ($process) {
        $this->commands->push(remoteProcessScript($process));

        return Process::result(output: '');
    });
});

afterEach(function () {
    Server::flushIdentityMap();
});

function softDeleteServer(Server $server): void
{
    // "Delete server" soft-deletes the server before DeleteServer runs in the queue.
    $server->delete();
    Server::flushIdentityMap();
}

it('removes the containers of the resources from a working server before the server is deleted', function () {
    softDeleteServer($this->server);

    DeleteServer::run($this->server->id);

    expect($this->commands->implode("\n"))->toContain($this->service->uuid)
        ->and(Service::withTrashed()->find($this->service->id))->toBeNull()
        ->and(ServiceApplication::where('service_id', $this->service->id)->exists())->toBeFalse()
        ->and(Server::withTrashed()->find($this->server->id))->toBeNull();
});

it('deletes the resources only from Coolify when they must stay on the server', function () {
    softDeleteServer($this->server);

    DeleteServer::run($this->server->id, removeResourcesFromServer: false);

    expect($this->commands->filter(fn (string $command) => str_contains($command, $this->service->uuid)))->toBeEmpty()
        ->and(Service::withTrashed()->find($this->service->id))->toBeNull()
        ->and(Server::withTrashed()->find($this->server->id))->toBeNull();
});

it('keeps the server when a resource cannot be deleted, so no resource is left without a server', function () {
    softDeleteServer($this->server);
    Service::forceDeleting(fn () => throw new RuntimeException('database error'));

    DeleteServer::run($this->server->id, removeResourcesFromServer: false);

    expect(Service::find($this->service->id))->not->toBeNull()
        ->and(Server::onlyTrashed()->find($this->server->id))->not->toBeNull()
        ->and(StandaloneDocker::where('server_id', $this->server->id)->exists())->toBeTrue();
});

it('passes the choice to keep the resources on the server from the delete dialog', function (array $selectedActions, bool $removeFromServer) {
    $this->actingAs($this->user);
    $component = Livewire::test(Delete::class, ['server_uuid' => $this->server->uuid]);
    expect($component->get('remove_resources_from_server'))->toBeTrue();

    $component->call('delete', 'password', $selectedActions)->assertNotDispatched('error');

    expect(Service::find($this->service->id))->not->toBeNull();
    DeleteServer::assertPushed(fn ($action, array $parameters) => $parameters[0] === $this->server->id && $parameters[11] === $removeFromServer);
})->with([
    'remove from the server' => [['force_delete_resources', 'remove_resources_from_server'], true],
    'keep on the server' => [['force_delete_resources'], false],
]);

it('passes the choice to keep the resources on the server from the API', function (string $query, bool $removeFromServer) {
    $token = $this->user->createToken('server-delete', ['write', 'deploy', 'delete'])->plainTextToken;
    InstanceSettings::query()->update(['is_api_enabled' => true]);

    $this->withHeaders(['Authorization' => 'Bearer '.$token])
        ->deleteJson("/api/v1/servers/{$this->server->uuid}?force=true{$query}")
        ->assertOk();

    DeleteServer::assertPushed(fn ($action, array $parameters) => $parameters[0] === $this->server->id && $parameters[11] === $removeFromServer);
})->with([
    'default' => ['', true],
    'keep on the server' => ['&remove_resources_from_server=false', false],
]);

it('deletes the resources of a stuck deleted server from Coolify before the server', function () {
    softDeleteServer($this->server);

    $this->artisan('cleanup:stucked-resources');

    expect($this->commands->filter(fn (string $command) => str_contains($command, $this->service->uuid)))->toBeEmpty()
        ->and(Service::withTrashed()->find($this->service->id))->toBeNull()
        ->and(Server::withTrashed()->find($this->server->id))->toBeNull();
});
