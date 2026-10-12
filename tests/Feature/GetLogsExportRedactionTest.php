<?php

use App\Livewire\Project\Shared\GetLogs;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
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
use Livewire\Livewire;

uses(RefreshDatabase::class);

const GET_LOGS_EXPORT_CANARY = 'coolify-canary-exported-env-value';

beforeEach(function () {
    $user = User::factory()->create();
    $team = Team::factory()->create();
    $user->teams()->attach($team, ['role' => 'owner']);

    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
    ]);
    $this->server->settings->fill(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false])->save();
    $this->server = Server::with('settings')->findOrFail($this->server->id);
    $destination = StandaloneDocker::query()->where('server_id', $this->server->id)->firstOrFail();
    $environment = Environment::factory()->create(['project_id' => Project::factory()->create(['team_id' => $team->id])->id]);
    $destinationAttributes = ['destination_id' => $destination->id, 'destination_type' => $destination->getMorphClass()];

    $this->application = Application::factory()->create(['environment_id' => $environment->id, ...$destinationAttributes]);
    $this->service = Service::factory()->create(['environment_id' => $environment->id, 'server_id' => $this->server->id, ...$destinationAttributes]);
    $this->serviceApplication = ServiceApplication::create(['name' => 'web', 'service_id' => $this->service->id]);

    foreach ([$this->application, $this->service] as $resource) {
        EnvironmentVariable::query()->create([
            'key' => 'EXPORT_CANARY',
            'value' => GET_LOGS_EXPORT_CANARY,
            'resourceable_type' => $resource->getMorphClass(),
            'resourceable_id' => $resource->id,
            'is_preview' => false,
        ]);
    }

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Process::fake(['*' => Process::result(output: '2026-09-28T10:00:01Z booted with '.GET_LOGS_EXPORT_CANARY)]);
});

it('redacts the resource environment values when copying or downloading logs', function (string $resource, string $action) {
    $parameters = $resource === 'application'
        ? ['resource' => $this->application]
        : ['resource' => $this->service, 'servicesubtype' => $this->serviceApplication];

    Livewire::test(GetLogs::class, ['server' => $this->server, 'container' => 'test-container', ...$parameters])
        ->call($action)
        ->assertReturned('2026-09-28T10:00:01Z booted with '.REDACTED);
})->with(['application', 'service'])->with(['copyLogs', 'downloadAllLogs']);
