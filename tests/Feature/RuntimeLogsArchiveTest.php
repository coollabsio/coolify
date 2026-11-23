<?php

use App\Livewire\Project\Shared\GetLogs;
use App\Livewire\Project\Shared\Logs;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $server->settings->update(['is_reachable' => true, 'is_usable' => true]);
    $this->server = Server::with('settings')->findOrFail($server->id);
    $destination = $this->server->standaloneDockers()->firstOrFail();
    $this->application = Application::factory()->create([
        'environment_id' => Environment::factory()->create([
            'project_id' => Project::factory()->create(['team_id' => $this->team->id])->id,
        ])->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
});

function fakeServer(string $dockerPs, string $archiveList): void
{
    Process::fake(function (PendingProcess $process) use ($dockerPs, $archiveList) {
        if (str_contains($process->command, 'container-logs')) {
            return Process::result(output: $archiveList);
        }

        return Process::result(output: $dockerPs);
    });
}

function loadedLogs(Application $application, Server $server): Logs
{
    $component = new Logs;
    $component->type = 'application';
    $component->resource = $application;
    $component->servers = collect([$server]);
    $component->loadAllContainers();

    return $component;
}

it('selects the newest archive when every container was removed', function () {
    fakeServer('', "1790000000.0 app-a.aaaaaaaaaaaa.log\n1790000900.0 app-b.bbbbbbbbbbbb.log\n");

    $component = loadedLogs($this->application, $this->server);

    expect(array_column($component->archives, 'file'))->toBe(['app-b.bbbbbbbbbbbb.log', 'app-a.aaaaaaaaaaaa.log'])
        ->and($component->archive)->toBe($this->server->uuid.'/app-b.bbbbbbbbbbbb.log');
});

it('keeps live logs and hides archives of containers Docker still has', function () {
    $container = json_encode([
        'ID' => 'cccccccccccc',
        'Names' => $this->application->uuid.'-123',
        'State' => 'exited',
        'Labels' => 'coolify.applicationUuid='.$this->application->uuid,
    ]);
    fakeServer($container, "1790000000.0 {$this->application->uuid}-123.cccccccccccc.log\n1780000000.0 old.aaaaaaaaaaaa.log\n");

    $component = loadedLogs($this->application, $this->server);

    expect(array_column($component->archives, 'file'))->toBe(['old.aaaaaaaaaaaa.log'])
        ->and($component->archive)->toBe('');
});

it('reads an archived container from the archive file, not docker logs', function () {
    Process::fake(['*' => Process::result(output: "2026-10-27T10:00:00.000000000Z boom\n2026-10-27T10:00:01.000000000Z [coolify] Container exited with code 3")]);

    Livewire::test(GetLogs::class, [
        'server' => $this->server,
        'resource' => $this->application,
        'container' => 'app-b',
        'archiveFile' => 'app-b.bbbbbbbbbbbb.log',
    ])
        ->assertDontSeeHtml('title="Stream Logs"')
        ->call('getLogs')
        ->assertReturned("2026-10-27T10:00:00.000000000Z boom\n2026-10-27T10:00:01.000000000Z [coolify] Container exited with code 3");

    $path = "/data/coolify/sentinel/container-logs/{$this->application->uuid}/app-b.bbbbbbbbbbbb.log";
    Process::assertRan(fn ($process) => str_contains($process->command, "cat '{$path}.1' '{$path}' 2>/dev/null | tail -n 100"));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, 'docker logs'));
});

it('does not let the client replace the archive file', function () {
    Livewire::test(GetLogs::class, [
        'server' => $this->server,
        'resource' => $this->application,
        'container' => 'app-b',
        'archiveFile' => 'app-b.bbbbbbbbbbbb.log',
    ])->set('archiveFile', '../../etc/passwd');
})->throws(CannotUpdateLockedPropertyException::class);

it('hides archives from a team member who cannot update the resource', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);
    Process::fake();

    Livewire::test(GetLogs::class, [
        'server' => $this->server,
        'resource' => $this->application,
        'container' => 'app-b',
        'archiveFile' => 'app-b.bbbbbbbbbbbb.log',
    ])
        ->call('getLogs')
        ->assertReturned('Unauthorized.');

    Process::assertNothingRan();
});
