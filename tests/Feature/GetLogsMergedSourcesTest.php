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
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = functionalLogServer($this->team->id, $privateKey->id, 'Frankfurt');
    $this->edge = functionalLogServer($this->team->id, $privateKey->id, 'Helsinki');
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->firstOrFail();
    $edgeDestination = StandaloneDocker::where('server_id', $this->edge->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'build_pack' => 'dockerfile',
    ]);
    $this->application->additional_networks()->attach($edgeDestination->id, ['server_id' => $this->edge->id]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function functionalLogServer(int $teamId, int $privateKeyId, string $name): Server
{
    $server = Server::factory()->create([
        'team_id' => $teamId,
        'private_key_id' => $privateKeyId,
        'name' => $name,
    ]);
    $server->settings->fill([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ])->save();

    return Server::with('settings')->findOrFail($server->id);
}

function mergedLogSources(): array
{
    return [
        ['server' => test()->server->uuid, 'container' => 'web-frankfurt', 'label' => 'Frankfurt'],
        ['server' => test()->edge->uuid, 'container' => 'web-helsinki', 'label' => 'Helsinki'],
    ];
}

it('rejects a foreign server or an invalid container name before reading logs', function () {
    $otherTeam = Team::factory()->create();
    $foreign = functionalLogServer($otherTeam->id, $this->server->private_key_id, 'Foreign');
    $unrelated = functionalLogServer($this->team->id, $this->server->private_key_id, 'Unrelated');

    $rejected = [
        [
            ['server' => $this->server->uuid, 'container' => 'web-frankfurt', 'label' => 'Frankfurt'],
            ['server' => $foreign->uuid, 'container' => 'web-foreign', 'label' => 'Foreign'],
        ],
        [
            ['server' => $unrelated->uuid, 'container' => 'web', 'label' => 'Unrelated'],
        ],
        [
            ['server' => $this->server->uuid, 'container' => 'web-frankfurt', 'label' => 'Frankfurt'],
            ['server' => $this->edge->uuid, 'container' => 'bad name', 'label' => 'Bad'],
        ],
    ];

    foreach ($rejected as $sources) {
        Process::fake();

        Livewire::test(GetLogs::class, [
            'server' => $this->server,
            'resource' => $this->application,
            'container' => 'web-frankfurt',
            'sources' => $sources,
        ])
            ->call('getLogs')
            ->assertReturned('Unauthorized.');

        Process::assertNothingRan();
    }

    $this->edge->updateQuietly(['team_id' => $otherTeam->id]);
    Process::fake();

    Livewire::test(GetLogs::class, [
        'server' => $this->server,
        'resource' => $this->application,
        'container' => 'web-helsinki',
        'sources' => [
            ['server' => $this->edge->uuid, 'container' => 'web-helsinki', 'label' => 'Helsinki'],
        ],
    ])
        ->call('getLogs')
        ->assertReturned('Unauthorized.');

    Process::assertNothingRan();
});

it('merges container logs by timestamp and tags each line with its source', function () {
    Process::fake(function (PendingProcess $process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        if (str_contains($command, 'web-helsinki')) {
            return Process::result(output: "2026-09-28T10:00:02Z second\n2026-09-28T10:00:01Z first\n");
        }
        if (str_contains($command, 'web-frankfurt')) {
            return Process::result(output: "2026-09-28T10:00:02Z frankfurt\n");
        }

        return Process::result();
    });

    $component = Livewire::test(GetLogs::class, [
        'server' => $this->server,
        'resource' => $this->application,
        'container' => 'web-frankfurt',
        'sources' => mergedLogSources(),
        'showTimeStamps' => true,
    ]);

    $component->call('getLogs')->assertReturned(implode("\n", [
        "Helsinki\t2026-09-28T10:00:01Z first",
        "Frankfurt\t2026-09-28T10:00:02Z frankfurt",
        "Helsinki\t2026-09-28T10:00:02Z second",
    ]));

    $component->call('downloadAllLogs')->assertReturned(implode("\n", [
        'Helsinki | first',
        'Frankfurt | frankfurt',
        'Helsinki | second',
    ]));

    $component->set('showTimeStamps', true)
        ->call('downloadAllLogs')
        ->assertReturned(implode("\n", [
            'Helsinki | 2026-09-28T10:00:01Z first',
            'Frankfurt | 2026-09-28T10:00:02Z frankfurt',
            'Helsinki | 2026-09-28T10:00:02Z second',
        ]));
});

it('asks each source only for lines after its own timestamp', function () {
    $commands = [];
    Process::fake(function (PendingProcess $process) use (&$commands) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        $commands[] = $command;

        return Process::result(output: "2026-09-28T10:00:03Z line\n");
    });

    Livewire::test(GetLogs::class, [
        'server' => $this->server,
        'resource' => $this->application,
        'container' => 'web-frankfurt',
        'sources' => mergedLogSources(),
    ])->call('getLogs', [
        'Frankfurt' => '2026-09-28T10:00:01.123456789Z',
        'Helsinki' => 'not-a-timestamp',
    ]);

    $frankfurt = collect($commands)->first(fn (string $command): bool => str_contains($command, 'web-frankfurt'));
    $helsinki = collect($commands)->first(fn (string $command): bool => str_contains($command, 'web-helsinki'));

    expect($frankfurt)->toContain('--since 2026-09-28T10:00:01.123456789Z')
        ->and($helsinki)->toContain('docker logs -n ')
        ->and($helsinki)->not->toContain('--since');
});
