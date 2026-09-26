<?php

use App\Livewire\Project\Shared\ExecuteContainerCommand;
use App\Livewire\Project\Shared\Terminal;
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
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config([
        'app.maintenance.store' => 'array',
        'constants.ssh.mux_enabled' => false,
    ]);
    Cache::flush();

    $this->team = Team::factory()->create();
    $this->owner = User::factory()->create();
    $this->owner->teams()->attach($this->team, ['role' => 'owner']);
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $this->privateKey->id,
    ]);
    $this->server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'is_terminal_enabled' => true,
    ]);

    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);
});

/**
 * Fake the SSH commands used before a container terminal token is issued.
 */
function fakeTerminalContainer(string $state, bool $hasShell = true, array $runningContainers = []): void
{
    Process::fake(function ($process) use ($state, $hasShell, $runningContainers) {
        $command = (string) $process->command;

        if (str_contains($command, 'docker ps -a')) {
            return Process::result(output: collect($runningContainers)
                ->map(fn (string $name) => json_encode(['Names' => $name, 'State' => 'running', 'Labels' => '']))
                ->implode("\n"));
        }

        if (str_contains($command, 'docker inspect')) {
            return Process::result(output: json_encode(['State' => ['Status' => $state]]));
        }

        if (str_contains($command, "-c 'exit 0'")) {
            return $hasShell ? Process::result() : Process::result(exitCode: 1);
        }

        return Process::result();
    });
}

function actAsTerminalMember(Team $team): void
{
    $member = User::factory()->create();
    $member->teams()->attach($team, ['role' => 'member']);
    test()->actingAs($member);
    session(['currentTeam' => $team]);
}

/**
 * Make `get_route_parameters()` return the page parameters. Livewire::test() mounts the
 * component through its own testing route, so add the parameters to every matched route.
 */
function bindTerminalRouteParameters(array $parameters): void
{
    Event::listen(RouteMatched::class, function (RouteMatched $event) use ($parameters): void {
        foreach ($parameters as $name => $value) {
            $event->route->setParameter($name, $value);
        }
    });
}

function createTerminalApplication(Server $server, Team $team): Application
{
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
}

describe('Terminal::sendTerminalCommand', function () {
    it('issues a token for a running container with a shell', function () {
        fakeTerminalContainer('running');

        Livewire::test(Terminal::class)
            ->call('sendTerminalCommand', true, 'app-container', $this->server->uuid)
            ->assertDispatched('send-terminal-token')
            ->assertNotDispatched(Terminal::SESSION_FAILED_EVENT);
    });

    it('reports a stopped container instead of leaving the terminal connecting', function () {
        fakeTerminalContainer('exited');

        Livewire::test(Terminal::class)
            ->call('sendTerminalCommand', true, 'app-container', $this->server->uuid)
            ->assertDispatched(Terminal::SESSION_FAILED_EVENT, message: 'The container is not running.')
            ->assertNotDispatched('send-terminal-token');
    });

    it('reports a container without a shell', function () {
        fakeTerminalContainer('running', hasShell: false);

        Livewire::test(Terminal::class)
            ->call('sendTerminalCommand', true, 'app-container', $this->server->uuid)
            ->assertDispatched(Terminal::SESSION_FAILED_EVENT, message: 'No shell is available in this container.')
            ->assertSet('hasShell', false)
            ->assertNotDispatched('send-terminal-token');
    });

    it('reports an invalid container name without running remote commands', function () {
        Process::fake();

        Livewire::test(Terminal::class)
            ->call('sendTerminalCommand', true, '-oProxyCommand=id', $this->server->uuid)
            ->assertDispatched(Terminal::SESSION_FAILED_EVENT, message: 'The container name is not valid.')
            ->assertNotDispatched('send-terminal-token');

        Process::assertNothingRan();
    });

    it('reports a server with terminal access disabled', function () {
        Process::fake();
        $this->server->settings->update(['is_terminal_enabled' => false]);

        Livewire::test(Terminal::class)
            ->call('sendTerminalCommand', false, $this->server->name, $this->server->uuid)
            ->assertDispatched(Terminal::SESSION_FAILED_EVENT, message: 'Terminal access is disabled on this server.')
            ->assertNotDispatched('send-terminal-token');

        Process::assertNothingRan();
    });

    it('denies members with a generic message and no token', function () {
        Process::fake();
        actAsTerminalMember($this->team);

        Livewire::test(Terminal::class)
            ->call('sendTerminalCommand', true, 'app-container', $this->server->uuid)
            ->assertDispatched(Terminal::SESSION_FAILED_EVENT, message: Terminal::NOT_ALLOWED_MESSAGE)
            ->assertNotDispatched('send-terminal-token');

        Process::assertNothingRan();
    });

    it('denies a server of another team with the same generic message', function () {
        Process::fake();
        $otherTeam = Team::factory()->create();
        $otherServer = Server::factory()->create([
            'team_id' => $otherTeam->id,
            'private_key_id' => $this->privateKey->id,
        ]);

        Livewire::test(Terminal::class)
            ->call('sendTerminalCommand', false, $otherServer->name, $otherServer->uuid)
            ->assertDispatched(Terminal::SESSION_FAILED_EVENT, message: Terminal::NOT_ALLOWED_MESSAGE)
            ->assertNotDispatched('send-terminal-token');

        Process::assertNothingRan();
    });
});

describe('ExecuteContainerCommand', function () {
    it('reports a denied server terminal to the terminal component', function () {
        Process::fake();
        bindTerminalRouteParameters(['server_uuid' => $this->server->uuid]);
        $component = Livewire::test(ExecuteContainerCommand::class);

        actAsTerminalMember($this->team);

        $component->call('connectToServer')
            ->assertDispatchedTo(Terminal::class, Terminal::SESSION_FAILED_EVENT, message: Terminal::NOT_ALLOWED_MESSAGE)
            ->assertNotDispatched('send-terminal-command');
    });

    it('reports a disabled server to the terminal component', function () {
        Process::fake();
        bindTerminalRouteParameters(['server_uuid' => $this->server->uuid]);
        $component = Livewire::test(ExecuteContainerCommand::class);

        $this->server->settings->update(['force_disabled' => true]);

        $component->call('connectToServer')
            ->assertDispatchedTo(Terminal::class, Terminal::SESSION_FAILED_EVENT, message: 'Server is disabled.')
            ->assertNotDispatched('send-terminal-command');
    });

    it('reports a missing container selection to the terminal component', function () {
        fakeTerminalContainer('running');
        $application = createTerminalApplication($this->server, $this->team);
        bindTerminalRouteParameters(['application_uuid' => $application->uuid]);

        Livewire::test(ExecuteContainerCommand::class)
            ->call('connectToContainer')
            ->assertDispatchedTo(Terminal::class, Terminal::SESSION_FAILED_EVENT, message: 'Please select a container.')
            ->assertNotDispatched('send-terminal-command');
    });

    it('reports a container that is no longer in the running list', function () {
        fakeTerminalContainer('running');
        $application = createTerminalApplication($this->server, $this->team);
        bindTerminalRouteParameters(['application_uuid' => $application->uuid]);

        Livewire::test(ExecuteContainerCommand::class)
            ->set('selected_container', "{$this->server->uuid}:gone-container")
            ->assertDispatchedTo(Terminal::class, Terminal::SESSION_FAILED_EVENT, message: 'Container not found.')
            ->assertNotDispatched('send-terminal-command');
    });

    it('denies members before it looks up the container', function () {
        fakeTerminalContainer('running');
        $application = createTerminalApplication($this->server, $this->team);
        bindTerminalRouteParameters(['application_uuid' => $application->uuid]);
        $component = Livewire::test(ExecuteContainerCommand::class);

        actAsTerminalMember($this->team);

        $component->set('selected_container', "{$this->server->uuid}:app-container")
            ->assertDispatchedTo(Terminal::class, Terminal::SESSION_FAILED_EVENT, message: Terminal::NOT_ALLOWED_MESSAGE)
            ->assertNotDispatched('send-terminal-command');
    });

    it('auto-connects when exactly one container is running', function () {
        fakeTerminalContainer('running', runningContainers: ['app-container']);
        $application = createTerminalApplication($this->server, $this->team);
        bindTerminalRouteParameters(['application_uuid' => $application->uuid]);

        Livewire::test(ExecuteContainerCommand::class)
            ->call('loadContainers')
            ->assertDispatched('send-terminal-command')
            ->assertNotDispatched(Terminal::AUTO_START_CANCELLED_EVENT)
            ->assertNotDispatched(Terminal::SESSION_FAILED_EVENT);
    });

    it('cancels the terminal auto-start when the user must choose a container', function () {
        fakeTerminalContainer('running', runningContainers: ['app-container-a', 'app-container-b']);
        $application = createTerminalApplication($this->server, $this->team);
        bindTerminalRouteParameters(['application_uuid' => $application->uuid]);

        Livewire::test(ExecuteContainerCommand::class)
            ->call('loadContainers')
            ->assertDispatchedTo(Terminal::class, Terminal::AUTO_START_CANCELLED_EVENT)
            ->assertNotDispatched('send-terminal-command');
    });
});
