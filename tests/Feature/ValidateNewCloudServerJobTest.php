<?php

use App\Jobs\ValidateAndInstallServerJob;
use App\Jobs\ValidateNewCloudServerJob;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create(['id' => 0]));
    Bus::fake([ValidateNewCloudServerJob::class, ValidateAndInstallServerJob::class]);

    $team = Team::factory()->create();
    $token = CloudProviderToken::create([
        'team_id' => $team->id,
        'provider' => 'hetzner',
        'token' => 'test-hetzner-token',
        'name' => 'Test Hetzner Token',
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $team->id,
        'private_key_id' => PrivateKey::factory()->create(['team_id' => $team->id])->id,
        'cloud_provider_token_id' => $token->id,
        'hetzner_server_id' => 456,
        'hetzner_server_status' => 'initializing',
        'ip' => Server::PLACEHOLDER_IP,
    ]);
});

function fakeNewHetznerServer(string $status, ?string $ip = '203.0.113.10'): void
{
    Http::fake([
        'https://api.hetzner.cloud/v1/servers/456' => Http::response([
            'server' => [
                'id' => 456,
                'status' => $status,
                'public_net' => ['ipv4' => ['ip' => $ip]],
            ],
        ], 200),
    ]);
}

it('validates and installs the server when it runs and accepts SSH', function () {
    fakeNewHetznerServer('running');
    Process::fake();

    (new ValidateNewCloudServerJob($this->server))->handle();

    Bus::assertDispatched(ValidateAndInstallServerJob::class, fn ($job) => $job->server->is($this->server));
    Bus::assertNotDispatched(ValidateNewCloudServerJob::class);
    expect($this->server->fresh()->ip)->toBe('203.0.113.10');
});

it('checks again later while the server is still starting', function () {
    fakeNewHetznerServer('initializing');

    (new ValidateNewCloudServerJob($this->server, attempt: 3))->handle();

    Bus::assertDispatched(ValidateNewCloudServerJob::class, fn ($job) => $job->attempt === 4);
    Bus::assertNotDispatched(ValidateAndInstallServerJob::class);
});

it('checks again later while SSH is not ready', function () {
    fakeNewHetznerServer('running');
    Process::fake(['*' => Process::result(errorOutput: 'Connection refused', exitCode: 255)]);

    (new ValidateNewCloudServerJob($this->server))->handle();

    Bus::assertDispatched(ValidateNewCloudServerJob::class, fn ($job) => $job->attempt === 2);
    Bus::assertNotDispatched(ValidateAndInstallServerJob::class);
});

it('stops and tells the user to validate manually after the last attempt', function () {
    fakeNewHetznerServer('initializing');

    (new ValidateNewCloudServerJob($this->server, attempt: ValidateNewCloudServerJob::MAX_ATTEMPTS))->handle();

    Bus::assertNotDispatched(ValidateNewCloudServerJob::class);
    Bus::assertNotDispatched(ValidateAndInstallServerJob::class);
    expect($this->server->fresh()->validation_logs)->toContain('Validate it manually.');
});

it('does nothing when the user already started a validation', function () {
    $this->server->update(['is_validating' => true]);
    Http::fake();

    (new ValidateNewCloudServerJob($this->server))->handle();

    Http::assertNothingSent();
    Bus::assertNothingDispatched();
});
