<?php

use App\Actions\Server\ValidateServer;
use App\Livewire\Server\ValidateAndInstall;
use App\Livewire\Settings\Advanced;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0, 'is_api_enabled' => true]);
    config(['constants.coolify.self_hosted' => false, 'constants.ssh.mux_enabled' => false]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $this->team->id])->id,
    ]);
    $this->token = $this->user->createToken('server-validation', ['write'])->plainTextToken;

    Queue::fake();
});

function validateServerViaApi(Server $server): TestResponse
{
    return test()->withHeaders(['Authorization' => 'Bearer '.test()->token])
        ->postJson("/api/v1/servers/{$server->uuid}/validate");
}

it('blocks API validations of one IP after 10 attempts on cloud', function () {
    foreach (range(1, 10) as $attempt) {
        validateServerViaApi($this->server)->assertCreated();
    }

    validateServerViaApi($this->server)
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('message', fn (string $message): bool => str_starts_with($message, 'Too many server validations.'));
    ValidateServer::assertPushed(10);
});

it('uses the rate limit window from the instance settings', function () {
    InstanceSettings::findOrFail(0)->forceFill(['server_validation_rate_limit_seconds' => 120])->save();
    Once::flush();

    foreach (range(1, 10) as $attempt) {
        validateServerViaApi($this->server)->assertCreated();
    }

    $retryAfter = (int) validateServerViaApi($this->server)->assertStatus(429)->headers->get('Retry-After');
    expect($retryAfter)->toBeGreaterThan(0)->toBeLessThanOrEqual(120);
});

it('uses the maximum failed validations per IP and per team from the instance settings', function () {
    InstanceSettings::findOrFail(0)->forceFill([
        'server_validation_max_failures_per_ip' => 2,
        'server_validation_max_failures_per_team' => 3,
    ])->save();
    Once::flush();
    $otherServer = Server::factory()->create(['team_id' => $this->team->id]);

    validateServerViaApi($this->server)->assertCreated();
    validateServerViaApi($this->server)->assertCreated();
    validateServerViaApi($this->server)->assertStatus(429);

    validateServerViaApi($otherServer)->assertCreated();
    validateServerViaApi($otherServer)->assertStatus(429);
});

it('lets an instance admin change the validation limits on cloud', function () {
    $rootTeam = Team::factory()->create(['id' => 0]);
    $rootTeam->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $rootTeam]);

    Livewire::test(Advanced::class)
        ->assertSet('server_validation_rate_limit_seconds', 600)
        ->set('server_validation_rate_limit_seconds', 30)
        ->call('submit')
        ->assertDispatched('error');

    expect(InstanceSettings::findOrFail(0)->server_validation_rate_limit_seconds)->toBe(600);

    Livewire::test(Advanced::class)
        ->assertSet('server_validation_max_failures_per_ip', 10)
        ->assertSet('server_validation_max_failures_per_team', 30)
        ->set('server_validation_rate_limit_seconds', 1800)
        ->set('server_validation_max_failures_per_ip', 5)
        ->set('server_validation_max_failures_per_team', 50)
        ->call('submit')
        ->assertHasNoErrors();

    expect(InstanceSettings::findOrFail(0))
        ->server_validation_rate_limit_seconds->toBe(1800)
        ->server_validation_max_failures_per_ip->toBe(5)
        ->server_validation_max_failures_per_team->toBe(50);
});

it('blocks API validations of a team after 30 attempts on cloud, also for different IPs', function () {
    $servers = Server::factory()->count(31)->create(['team_id' => $this->team->id]);

    foreach ($servers->take(30) as $server) {
        validateServerViaApi($server)->assertCreated();
    }

    validateServerViaApi($servers->last())->assertStatus(429);
});

it('blocks validations of one IP from different teams', function () {
    $otherTeam = Team::factory()->create();

    foreach (range(1, 10) as $attempt) {
        Server::throttleValidation($this->team->id, $this->server->ip);
    }

    expect(fn () => Server::throttleValidation($otherTeam->id, $this->server->ip))
        ->toThrow(ThrottleRequestsException::class);
});

it('does not limit validations on self-hosted instances', function () {
    config(['constants.coolify.self_hosted' => true]);

    foreach (range(1, 31) as $attempt) {
        validateServerViaApi($this->server)->assertCreated();
    }
});

it('gives the attempt back when the connection succeeds', function () {
    Process::fake();

    foreach (range(1, 12) as $attempt) {
        Server::throttleValidation($this->team->id, $this->server->ip);
        expect($this->server->validateConnection()['uptime'])->toBeTrue();
    }

    validateServerViaApi($this->server)->assertCreated();
});

it('tries a failed SSH login only once', function () {
    config(['constants.ssh.retry_base_delay' => 0, 'constants.ssh.retry_max_delay' => 0]);
    Process::fake(['*' => Process::result(errorOutput: 'ssh: connect to host 192.0.2.1 port 22: Connection refused', exitCode: 255)]);

    expect($this->server->validateConnection()['uptime'])->toBeFalse();

    Process::assertRanTimes(fn ($process): bool => str_contains(implode(' ', (array) $process->command), 'ssh'), 1);
});

it('does not connect to the server when the retry button is throttled on cloud', function () {
    foreach (range(1, 10) as $attempt) {
        Server::throttleValidation($this->team->id, $this->server->ip);
    }

    $this->actingAs($this->user);
    $serverMock = Mockery::mock($this->server)->makePartial();
    $serverMock->shouldNotReceive('validateConnection');

    $component = Livewire::test(ValidateAndInstall::class, ['server' => $serverMock])->instance();
    $component->retry();

    expect($component->uptime)->toBeNull();
});

it('skips the later validation steps when no connection succeeded', function () {
    $this->actingAs($this->user);
    $serverMock = Mockery::mock($this->server)->makePartial();
    $serverMock->shouldNotReceive('validateOS');
    $serverMock->shouldNotReceive('validatePrerequisites');
    $serverMock->shouldNotReceive('validateDockerEngine');
    $serverMock->shouldNotReceive('validateDockerEngineVersion');

    $component = Livewire::test(ValidateAndInstall::class, ['server' => $serverMock])->instance();
    $component->validateOS();
    $component->validatePrerequisites();
    $component->validateDockerEngine();
    $component->validateDockerVersion();
});
