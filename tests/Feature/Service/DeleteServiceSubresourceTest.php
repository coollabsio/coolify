<?php

use App\Livewire\Project\Service\Index;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    config()->set('app.maintenance.store', 'array');
    config()->set('constants.ssh.max_retries', 1);
    InstanceSettings::forceCreate(['id' => 0]);
    Server::flushIdentityMap();

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $team]);

    $this->server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $this->application = ServiceApplication::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'web',
        'service_id' => $this->service->id,
        'image' => 'example/web:latest',
    ]);
    $this->database = ServiceDatabase::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'postgres',
        'service_id' => $this->service->id,
        'image' => 'postgres:17',
    ]);
});

function markServerReachable(Server $server): void
{
    $privateKey = PrivateKey::factory()->create(['team_id' => $server->team_id]);
    $server->update(['private_key_id' => $privateKey->id]);
    $server->settings()->update(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false]);
    Server::flushIdentityMap();
}

dataset('service parts', [
    'application' => ['application', 'deleteApplication', ServiceApplication::class, 'Application'],
    'database' => ['database', 'deleteDatabase', ServiceDatabase::class, 'Database'],
]);

it('deletes a service part from Coolify when the server stops responding while still marked reachable', function (string $part, string $method, string $model, string $label) {
    markServerReachable($this->server);
    Process::fake(['*' => Process::result(errorOutput: 'ssh: connect to host 1.2.3.4 port 22: Connection refused', exitCode: 255)]);
    Log::spy();

    Livewire::test(Index::class, ['serviceApplication' => $this->{$part}->fresh(), 'embedded' => true])
        ->call($method, 'password')
        ->assertHasNoErrors()
        ->assertDispatched('warning', "{$label} deleted from Coolify. The server does not respond, so its container is removed when the service starts again.")
        ->assertNotDispatched('error');

    expect($model::find($this->{$part}->id))->toBeNull();
    Process::assertRan(fn ($process) => str_contains($process->command, 'docker rm -f $container_ids'));
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'service part'))->atLeast()->once();
})->with('service parts');

it('deletes a service part from Coolify without SSH when the server is known to be offline', function (string $part, string $method, string $model, string $label) {
    Process::fake();

    Livewire::test(Index::class, ['serviceApplication' => $this->{$part}->fresh(), 'embedded' => true])
        ->call($method, 'password')
        ->assertDispatched('warning', "{$label} deleted from Coolify. The server does not respond, so its container is removed when the service starts again.");

    expect($model::find($this->{$part}->id))->toBeNull();
    Process::assertNothingRan();
})->with('service parts');

it('removes the container of a service part and reports success when the server responds', function (string $part, string $method, string $model, string $label) {
    markServerReachable($this->server);
    Process::fake(['*' => Process::result(output: '')]);

    Livewire::test(Index::class, ['serviceApplication' => $this->{$part}->fresh(), 'embedded' => true])
        ->call($method, 'password')
        ->assertDispatched('success', "{$label} deleted.")
        ->assertNotDispatched('warning');

    expect($model::find($this->{$part}->id))->toBeNull();
    Process::assertRan(fn ($process) => str_contains($process->command, "coolify.service.subUuid={$this->{$part}->uuid}"));
})->with('service parts');
