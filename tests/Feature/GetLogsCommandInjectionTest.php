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
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Support\Facades\Process;
use Livewire\Attributes\Locked;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);
    // Server::created auto-creates a StandaloneDocker, reuse it
    $this->destination = StandaloneDocker::where('server_id', $this->server->id)->first();
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

describe('GetLogs locked properties', function () {
    test('container property has Locked attribute', function () {
        $property = new ReflectionProperty(GetLogs::class, 'container');
        $attributes = $property->getAttributes(Locked::class);

        expect($attributes)->not->toBeEmpty();
    });

    test('server property has Locked attribute', function () {
        $property = new ReflectionProperty(GetLogs::class, 'server');
        $attributes = $property->getAttributes(Locked::class);

        expect($attributes)->not->toBeEmpty();
    });

    test('resource property has Locked attribute', function () {
        $property = new ReflectionProperty(GetLogs::class, 'resource');
        $attributes = $property->getAttributes(Locked::class);

        expect($attributes)->not->toBeEmpty();
    });

    test('servicesubtype property has Locked attribute', function () {
        $property = new ReflectionProperty(GetLogs::class, 'servicesubtype');
        $attributes = $property->getAttributes(Locked::class);

        expect($attributes)->not->toBeEmpty();
    });
});

describe('GetLogs Livewire action validation', function () {
    test('getLogs marks ANSI-colored output truncated based on raw bytes', function () {
        $this->server->settings->fill([
            'is_reachable' => true,
            'is_usable' => true,
            'force_disabled' => false,
        ])->save();
        $server = Server::with('settings')->findOrFail($this->server->id);
        $output = "\e[31m".str_repeat('a', GetLogs::MAX_DISPLAY_SIZE_BYTES - 4);

        expect(strlen($output))->toBe(GetLogs::MAX_DISPLAY_SIZE_BYTES + 1);

        Process::shouldReceive('timeout')->once()->andReturnSelf();
        Process::shouldReceive('run')->andReturnUsing(function (string $command, ?callable $callback = null) use ($output): FakeProcessResult {
            if ($callback) {
                $callback('out', $output);
            }

            return new FakeProcessResult(command: $command);
        });

        $component = new GetLogs;
        $component->server = $server;
        $component->resource = $this->application;
        $component->container = 'test-container';
        $component->showTimeStamps = false;
        $component->getLogs(true);

        expect($component->outputs)
            ->toContain('[... Output truncated at 5MB limit ...]')
            ->not->toContain("\e[31m");
    });

    test('getLogs rejects invalid container name', function () {
        // Make server functional by setting settings directly
        $this->server->settings->fill([
            'is_reachable' => true,
            'is_usable' => true,
            'force_disabled' => false,
        ])->save();
        // Reload server with fresh settings to ensure casted values
        $server = Server::with('settings')->findOrFail($this->server->id);

        Livewire::test(GetLogs::class, [
            'server' => $server,
            'resource' => $this->application,
            'container' => 'container;malicious-command',
        ])
            ->call('getLogs')
            ->assertSet('outputs', 'Invalid container name.');
    });

    test('getLogs rejects unauthorized server access', function () {
        $otherTeam = Team::factory()->create();
        $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);

        Livewire::test(GetLogs::class, [
            'server' => $otherServer,
            'resource' => $this->application,
            'container' => 'test-container',
        ])
            ->call('getLogs')
            ->assertSet('outputs', 'Unauthorized.');
    });

    test('downloadAllLogs returns empty for invalid container name', function () {
        $this->server->settings->fill([
            'is_reachable' => true,
            'is_usable' => true,
            'force_disabled' => false,
        ])->save();
        $server = Server::with('settings')->findOrFail($this->server->id);

        Livewire::test(GetLogs::class, [
            'server' => $server,
            'resource' => $this->application,
            'container' => 'container$(whoami)',
        ])
            ->call('downloadAllLogs')
            ->assertReturned('');
    });

    test('downloadAllLogs returns empty for unauthorized server', function () {
        $otherTeam = Team::factory()->create();
        $otherServer = Server::factory()->create(['team_id' => $otherTeam->id]);

        Livewire::test(GetLogs::class, [
            'server' => $otherServer,
            'resource' => $this->application,
            'container' => 'test-container',
        ])
            ->call('downloadAllLogs')
            ->assertReturned('');
    });
});

describe('GetLogs stream polling', function () {
    test('streaming logs polls when log panel is not collapsible', function () {
        Livewire::test(GetLogs::class, [
            'server' => $this->server,
            'resource' => $this->application,
            'container' => 'coolify-sentinel',
            'collapsible' => false,
        ])
            ->assertDontSeeHtml('wire:poll.2000ms="getLogs(true)"')
            ->call('toggleStreamLogs')
            ->assertSeeHtml('wire:poll.2000ms="getLogs(true)"');
    });
});

describe('GetLogs container name injection payloads are blocked by validation', function () {
    test('newline injection payload is rejected', function () {
        // The exact PoC payload from the advisory
        $payload = "postgresql 2>/dev/null\necho '===RCE-START==='\nid\nwhoami\nhostname\ncat /etc/hostname\necho '===RCE-END==='\n#";
        expect(ValidationPatterns::isValidContainerName($payload))->toBeFalse();
    });

    test('semicolon injection payload is rejected', function () {
        expect(ValidationPatterns::isValidContainerName('postgresql;id'))->toBeFalse();
    });

    test('backtick injection payload is rejected', function () {
        expect(ValidationPatterns::isValidContainerName('postgresql`id`'))->toBeFalse();
    });

    test('command substitution injection payload is rejected', function () {
        expect(ValidationPatterns::isValidContainerName('postgresql$(whoami)'))->toBeFalse();
    });

    test('pipe injection payload is rejected', function () {
        expect(ValidationPatterns::isValidContainerName('postgresql|cat /etc/passwd'))->toBeFalse();
    });

    test('valid container names are accepted', function () {
        expect(ValidationPatterns::isValidContainerName('postgresql'))->toBeTrue();
        expect(ValidationPatterns::isValidContainerName('my-app-container'))->toBeTrue();
        expect(ValidationPatterns::isValidContainerName('service_db.v2'))->toBeTrue();
        expect(ValidationPatterns::isValidContainerName('coolify-proxy'))->toBeTrue();
    });
});
