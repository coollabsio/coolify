<?php

use App\Actions\Proxy\StartProxy;
use App\Enums\ProxyTypes;
use App\Events\ServerReachabilityChanged;
use App\Jobs\ValidateAndInstallServerJob;
use App\Livewire\Server\ValidateAndInstall;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    Event::fake([ServerReachabilityChanged::class]);

    $user = User::factory()->create();
    $this->team = Team::factory()->create();
    $user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'user' => 'root',
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
});

function fakeDockerVersionOutput(string $output, int $exitCode = 0): void
{
    Process::fake([
        '*compose*' => Process::result(output: 'v2.40.3', exitCode: 0),
        '*' => Process::result(output: $output, exitCode: $exitCode),
    ]);
}

function validateDockerVersionError(Server $server): ?string
{
    $component = Livewire::test(ValidateAndInstall::class, ['server' => $server])->instance();
    $component->uptime = '1 day';
    $component->validateDockerVersion();

    return $component->error;
}

it('accepts Docker Engine 29', function () {
    fakeDockerVersionOutput('{"Client":{"Version":"29.6.2"},"Server":{"Version":"29.6.2"}}');

    expect($this->server->validateDockerEngineVersion())->toBeTrue()
        ->and($this->server->fresh()->dockerVersion())->toBe('29.6.2');
});

it('reports an unreadable docker version instead of a too old one', function (string $output, int $exitCode) {
    fakeDockerVersionOutput($output, $exitCode);

    $error = validateDockerVersionError($this->server);

    expect($error)->toContain('could not read the Docker Engine version')
        ->toContain('Run "docker version --format json" on the server as the root user')
        ->not->toContain('Minimum Docker Engine version')
        ->and($this->server->fresh()->dockerVersion())->toBeNull()
        ->and($this->server->fresh()->validation_logs)->toBe($error);
})->with([
    'text before the json' => ['Welcome to the server'."\n".'{"Server":{"Version":"29.6.2"}}', 0],
    'command failed' => ['', 1],
]);

it('reports the detected version when docker is too old', function () {
    fakeDockerVersionOutput('{"Server":{"Version":"23.0.6"}}');

    expect(validateDockerVersionError($this->server))
        ->toContain('Docker Engine 23.0.6 is installed')
        ->toContain('version 24 or newer');
});

it('shows the sudo command for non-root servers', function () {
    $this->server->update(['user' => 'deploy']);
    fakeDockerVersionOutput('not json');

    expect(validateDockerVersionError($this->server->fresh()))
        ->toContain('Run "sudo docker version --format json" on the server as the deploy user');
});

it('validates a server without a proxy and does not start a proxy', function () {
    Queue::fake();
    $this->server->proxy->set('type', ProxyTypes::NONE->value);
    $this->server->save();
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true]);
    fakeDockerVersionOutput('{"Server":{"Version":"29.6.2"}}');

    expect(validateDockerVersionError($this->server->fresh()))->toBeNull()
        ->and($this->server->fresh()->is_validating)->toBeFalsy();
    Queue::assertNotPushed(StartProxy::class);
});

it('validates a server without a proxy in the background job', function () {
    Queue::fake();
    $this->server->proxy->set('type', ProxyTypes::NONE->value);
    $this->server->save();
    $this->server->settings->update(['is_reachable' => true, 'is_usable' => true]);

    $server = Mockery::mock($this->server->fresh())->makePartial();
    $server->shouldReceive('validateConnection')->andReturn(['uptime' => true, 'error' => null]);
    $server->shouldReceive('validateOS')->andReturn('debian');
    $server->shouldReceive('validatePrerequisites')->andReturn(['success' => true]);
    $server->shouldReceive('validateDockerEngine')->andReturn(true);
    $server->shouldReceive('validateDockerCompose')->andReturn(true);
    $server->shouldReceive('validateDockerEngineVersion')->andReturn(true);
    $server->shouldReceive('gatherServerMetadata')->andReturnNull();

    (new ValidateAndInstallServerJob($server))->handle();

    expect($this->server->fresh()->validation_logs)->toBeNull()
        ->and($this->server->fresh()->is_validating)->toBeFalsy();
    Queue::assertNotPushed(StartProxy::class);
});
