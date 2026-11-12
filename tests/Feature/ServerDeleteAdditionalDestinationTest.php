<?php

use App\Actions\Application\StopApplicationOneServer;
use App\Jobs\DeleteResourceJob;
use App\Livewire\Project\Shared\Destination;
use App\Livewire\Server\Delete;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();
    Queue::fake();
    config(['app.maintenance.store' => 'array']);
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->serverToDelete = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id, 'name' => 'worker-1', 'ip' => '203.0.113.51']);
    $this->destinationToDelete = StandaloneDocker::where('server_id', $this->serverToDelete->id)->firstOrFail();
    $this->otherServer = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $privateKey->id, 'name' => 'worker-2', 'ip' => '203.0.113.52']);
    $this->otherDestination = StandaloneDocker::where('server_id', $this->otherServer->id)->firstOrFail();

    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->makeApplication = fn (StandaloneDocker $destination, string $name): Application => Application::factory()->create([
        'name' => $name,
        'environment_id' => $this->environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

afterEach(function () {
    Server::flushIdentityMap();
});

function deleteServer(object $test, string $path, bool $force): void
{
    if ($path === 'api') {
        $token = $test->user->createToken('server-delete', ['write', 'deploy', 'delete'])->plainTextToken;
        $test->withHeaders(['Authorization' => 'Bearer '.$token])
            ->deleteJson("/api/v1/servers/{$test->serverToDelete->uuid}".($force ? '?force=true' : ''))
            ->assertOk();

        return;
    }

    $test->actingAs($test->user);
    Livewire::test(Delete::class, ['server_uuid' => $test->serverToDelete->uuid])
        ->call('delete', 'password', $force ? ['force_delete_resources'] : [])
        ->assertHasNoErrors()
        ->assertNotDispatched('error');
}

function additionalDestinationsOf(Application $application, Server $server): int
{
    return DB::table('additional_destinations')
        ->where('application_id', $application->id)
        ->where('server_id', $server->id)
        ->count();
}

test('deleting a server with force deletes its own applications and only detaches applications of other servers', function (string $path) {
    $additional = ($this->makeApplication)($this->otherDestination, 'ref-admin');
    $additional->additional_networks()->attach($this->destinationToDelete->id, ['server_id' => $this->serverToDelete->id]);
    $primary = ($this->makeApplication)($this->destinationToDelete, 'own-app');

    StopApplicationOneServer::shouldRun()->once()
        ->withArgs(fn (Application $application, Server $server) => $application->is($additional) && $server->is($this->serverToDelete));

    deleteServer($this, $path, force: true);

    expect(Server::withTrashed()->find($this->serverToDelete->id)->trashed())->toBeTrue()
        ->and(Application::find($additional->id))->not->toBeNull()
        ->and(additionalDestinationsOf($additional, $this->serverToDelete))->toBe(0);

    Queue::assertPushed(DeleteResourceJob::class, 1);
    Queue::assertPushed(DeleteResourceJob::class, fn (DeleteResourceJob $job) => $job->resource->is($primary));
})->with(['api', 'ui']);

test('a server with only additional-destination applications is deleted without force and detaches them', function (string $path) {
    $additional = ($this->makeApplication)($this->otherDestination, 'ref-admin');
    $additional->additional_networks()->attach($this->destinationToDelete->id, ['server_id' => $this->serverToDelete->id]);
    StopApplicationOneServer::shouldRun()->once();

    deleteServer($this, $path, force: false);

    expect(Server::withTrashed()->find($this->serverToDelete->id)->trashed())->toBeTrue()
        ->and(Application::find($additional->id))->not->toBeNull()
        ->and(additionalDestinationsOf($additional, $this->serverToDelete))->toBe(0);
    Queue::assertNotPushed(DeleteResourceJob::class);
})->with(['api', 'ui']);

test('an application promoted to another server survives the deletion of its old server', function () {
    $application = ($this->makeApplication)($this->destinationToDelete, 'ref-admin');
    $application->additional_networks()->attach($this->otherDestination->id, ['server_id' => $this->otherServer->id]);
    $this->otherServer->settings()->update(['is_reachable' => true, 'is_usable' => true]);
    StopApplicationOneServer::shouldRun()->once();

    $this->actingAs($this->user);
    Livewire::test(Destination::class, ['resource' => $application])
        ->call('promote', $this->otherDestination->id, $this->otherServer->id);
    expect(additionalDestinationsOf($application, $this->serverToDelete))->toBe(1);

    deleteServer($this, 'ui', force: true);

    $application = Application::find($application->id);
    expect($application)->not->toBeNull()
        ->and($application->destination_id)->toBe($this->otherDestination->id)
        ->and(additionalDestinationsOf($application, $this->serverToDelete))->toBe(0);
    Queue::assertNotPushed(DeleteResourceJob::class);
});

test('the delete confirmation lists the resources to delete and the applications to detach', function () {
    $additional = ($this->makeApplication)($this->otherDestination, 'ref-admin');
    $additional->additional_networks()->attach($this->destinationToDelete->id, ['server_id' => $this->serverToDelete->id]);
    ($this->makeApplication)($this->destinationToDelete, 'own-app');

    $this->actingAs($this->user);
    $component = Livewire::test(Delete::class, ['server_uuid' => $this->serverToDelete->uuid]);

    expect($component->viewData('checkboxes')[0]['label'])->toBe('Delete all resources (1 total): own-app')
        ->and($component->viewData('detachedApplications')->pluck('name')->all())->toBe(['ref-admin']);
    $component->assertSee('ref-admin will keep running on worker-2');
});
