<?php

use App\Livewire\Project\Shared\GetLogs;
use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Attributes\Locked;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);

    $this->server = Server::factory()->create(['team_id' => $this->team->id]);
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
    test('getLogs rejects invalid container name', function () {
        // Make server functional by setting settings directly
        $this->server->settings->forceFill([
            'is_reachable' => true,
            'is_usable' => true,
            'force_disabled' => false,
        ])->save();
        // Reload server with fresh settings to ensure casted values
        $server = Server::with('settings')->find($this->server->id);

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
        $this->server->settings->forceFill([
            'is_reachable' => true,
            'is_usable' => true,
            'force_disabled' => false,
        ])->save();
        $server = Server::with('settings')->find($this->server->id);

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
