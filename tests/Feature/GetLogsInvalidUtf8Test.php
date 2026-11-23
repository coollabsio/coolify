<?php

use App\Livewire\Project\Shared\GetLogs;
use App\Models\Application;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);
    $server->settings->fill(['is_reachable' => true, 'is_usable' => true, 'force_disabled' => false])->save();
    $this->server = Server::with('settings')->findOrFail($server->id);

    $destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'build_pack' => 'dockercompose',
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

it('returns container logs that contain invalid UTF-8 bytes', function () {
    Process::fake(['*' => Process::result(output: "2026-06-17T21:00:00Z job \xC3\x28 processed\n2026-06-17T21:00:01Z done\n")]);

    $logs = Livewire::test(GetLogs::class, [
        'server' => $this->server,
        'resource' => $this->application,
        'container' => 'worker-abc123',
    ])->call('getLogs')->effects['returns'][0];

    expect(mb_check_encoding($logs, 'UTF-8'))->toBeTrue()
        ->and($logs)->toContain('2026-06-17T21:00:00Z job ')
        ->and($logs)->toContain('processed')
        ->and($logs)->toContain('2026-06-17T21:00:01Z done');
});

it('keeps merged logs valid UTF-8 when the size limit cuts them', function (int $padding) {
    $line = '2026-06-17T21:00:00Z '.str_repeat('a', $padding).str_repeat('€', 1000);
    Process::fake(['*' => Process::result(output: implode("\n", array_fill(0, 1000, $line)))]);

    $logs = Livewire::test(GetLogs::class, [
        'server' => $this->server,
        'resource' => $this->application,
        'container' => 'worker-1',
        'sources' => [
            ['server' => $this->server->uuid, 'container' => 'worker-1', 'label' => 'worker-1'],
            ['server' => $this->server->uuid, 'container' => 'worker-2', 'label' => 'worker-2'],
        ],
    ])->call('getLogs')->effects['returns'][0];

    expect(mb_check_encoding($logs, 'UTF-8'))->toBeTrue()
        ->and($logs)->toEndWith('[... Output truncated at 5MB limit ...]');
})->with([0, 1, 2]);
