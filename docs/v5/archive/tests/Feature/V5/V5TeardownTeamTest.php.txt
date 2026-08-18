<?php

use App\Jobs\V5TeardownTeamJob;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Models\V5\Application as V5Application;
use App\Models\V5\RevokedAgentToken;
use App\Models\V5\Server as V5Server;
use App\Services\Flux\FluxClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::updateOrCreate(['id' => 0]);

    $this->owner = User::factory()->create();
    $this->team = Team::factory()->create(['name' => 'Teardown Team']);
    $this->team->members()->attach($this->owner->id, ['role' => 'owner']);
    $this->privateKey = PrivateKey::factory()->create(['team_id' => $this->team->id]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function createTeardownServer(Team $team, User $owner, PrivateKey $privateKey, array $attributes = []): V5Server
{
    return V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $owner->id,
        'private_key_id' => $privateKey->id,
        'name' => 'prod-'.fake()->unique()->numberBetween(1, 9999),
        'host' => fake()->unique()->ipv4(),
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        ...$attributes,
    ]);
}

function createTeardownApplication(Team $team, User $owner, V5Server $server): V5Application
{
    $project = Project::query()->create(['name' => fake()->unique()->company(), 'team_id' => $team->id]);
    // Project creation auto-provisions a default environment; reuse it rather
    // than colliding with the (name, project_id) unique index.
    $environment = $project->environments()->first()
        ?? Environment::query()->create(['name' => 'production', 'project_id' => $project->id]);

    // The checked-in testing-schema.sql dump predates the v5_applications.uuid
    // column, so skip the V5Model uuid-generating event (matching how the other
    // V5 suites hand-roll their schema) to insert against the dump.
    return V5Application::withoutEvents(fn () => V5Application::query()->forceCreate([
        'team_id' => $team->id,
        'project_id' => $project->id,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'created_by_user_id' => $owner->id,
        'name' => 'nginx-test',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-nginx-'.fake()->unique()->numerify('#######'),
        'status' => 'running',
    ]));
}

/**
 * @param  array<string, mixed>  $overrides
 * @param  array<int, array<string, mixed>>  $applications
 * @return array<string, mixed>
 */
function teardownServerPayload(array $overrides = [], array $applications = []): array
{
    return [
        'id' => 1,
        'uuid' => 'teardown-server-uuid',
        'name' => 'prod-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'node_address' => '203.0.113.10',
        'wireguard_management_ip' => '100.64.0.10',
        'is_ingress' => false,
        'ingress_type' => null,
        'status' => 'installed',
        'last_bootstrapped_at' => now()->toISOString(),
        'private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\ntest-key\n-----END OPENSSH PRIVATE KEY-----\n",
        'applications' => $applications,
        ...$overrides,
    ];
}

it('dispatches the v5 teardown job for the team servers when the team is deleted', function () {
    Queue::fake();

    $serverOne = createTeardownServer($this->team, $this->owner, $this->privateKey, [
        'host' => '203.0.113.10',
        'is_ingress' => true,
        'ingress_type' => 'caddy',
        'wireguard_management_ip' => '100.64.0.10',
        'last_bootstrapped_at' => now(),
    ]);
    createTeardownApplication($this->team, $this->owner, $serverOne);
    $serverTwo = createTeardownServer($this->team, $this->owner, $this->privateKey, [
        'host' => '203.0.113.11',
        'last_bootstrapped_at' => now(),
    ]);

    $this->team->delete();

    // The team deletion completes (the teardown hook never blocks it). The
    // per-row cascade is a database-level guarantee from the v5 migrations'
    // cascadeOnDelete() foreign keys; the in-memory SQLite test dump does not
    // enforce it, so it is not asserted here.
    expect(Team::find($this->team->id))->toBeNull();

    Queue::assertPushed(V5TeardownTeamJob::class, function (V5TeardownTeamJob $job): bool {
        if ($job->teamId !== $this->team->id || count($job->servers) !== 2) {
            return false;
        }

        $ingressServer = collect($job->servers)->firstWhere('host', '203.0.113.10');

        return $ingressServer !== null
            && $ingressServer['is_ingress'] === true
            && str_contains((string) $ingressServer['private_key'], 'BEGIN OPENSSH PRIVATE KEY')
            && count($ingressServer['applications']) === 1
            && str_starts_with((string) $ingressServer['applications'][0]['container_name'], 'coolify-v5-nginx-');
    });
});

it('does not dispatch teardown when the team owns no v5 servers', function () {
    Queue::fake();

    $this->team->delete();

    Queue::assertNotPushed(V5TeardownTeamJob::class);
});

it('runs the full per-server teardown sequence over ssh and flux', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $fluxClient = Mockery::mock(FluxClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('revokeFirewallRule')->andReturn('Firewall rule revoked.');
        $mock->shouldReceive('stopIngress')
            ->once()
            ->with(Mockery::type('string'), 'caddy')
            ->andReturn('Caddy ingress stopped.');
    });
    app()->instance(FluxClient::class, $fluxClient);

    $payload = teardownServerPayload([
        'is_ingress' => true,
        'ingress_type' => 'caddy',
    ], [
        ['id' => 5, 'container_name' => 'coolify-v5-nginx-abc', 'runtime_container_id' => 'runtime-1'],
    ]);

    (new V5TeardownTeamJob($this->team->id, [$payload]))->handle();

    // Application container removal ran over SSH.
    Process::assertRan(fn ($process) => is_array($process->command)
        && ($process->command[0] ?? null) === 'ssh'
        && str_contains((string) end($process->command), "rm -f 'coolify-v5-nginx-abc'"));

    // On-host bootstrap identity removal ran over SSH.
    Process::assertRan(fn ($process) => is_array($process->command)
        && ($process->command[0] ?? null) === 'ssh'
        && str_contains((string) end($process->command), '/etc/coolify/v5-node.json'));
});

it('keeps tearing down other servers when one host is unreachable', function () {
    Process::fake(['*' => Process::result(output: '')]);

    // The ingress server's Flux stop throws (unreachable host); teardown of the
    // rest must still proceed and the job must not fail.
    $fluxClient = Mockery::mock(FluxClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('revokeFirewallRule')->andReturn('Firewall rule revoked.');
        $mock->shouldReceive('stopIngress')
            ->with(Mockery::type('string'), 'caddy')
            ->andThrow(new RuntimeException('flux socket unreachable'));
    });
    app()->instance(FluxClient::class, $fluxClient);

    $unreachableIngress = teardownServerPayload([
        'id' => 1,
        'host' => '203.0.113.10',
        'wireguard_management_ip' => '100.64.0.10',
        'is_ingress' => true,
        'ingress_type' => 'caddy',
    ]);
    $healthyServer = teardownServerPayload([
        'id' => 2,
        'host' => '203.0.113.11',
        'wireguard_management_ip' => '100.64.0.11',
    ]);

    (new V5TeardownTeamJob($this->team->id, [$unreachableIngress, $healthyServer]))->handle();

    // The healthy server was still cleaned up despite the first host failing.
    Process::assertRan(fn ($process) => is_array($process->command)
        && in_array('root@203.0.113.11', $process->command, true)
        && str_contains((string) end($process->command), '/etc/coolify/v5-node.json'));

    // The unreachable ingress server's marker removal was still attempted.
    Process::assertRan(fn ($process) => is_array($process->command)
        && in_array('root@203.0.113.10', $process->command, true)
        && str_contains((string) end($process->command), '/etc/coolify/v5-node.json'));
});

it('captures each server agent token jti and expiry in the dispatched payload', function () {
    Queue::fake();

    $server = createTeardownServer($this->team, $this->owner, $this->privateKey, [
        'host' => '203.0.113.42',
        'agent_token_jti' => 'jti-captured',
        'agent_token_expires_at' => now()->addDay(),
        'last_bootstrapped_at' => now(),
    ]);

    $this->team->delete();

    Queue::assertPushed(V5TeardownTeamJob::class, function (V5TeardownTeamJob $job): bool {
        $payload = collect($job->servers)->firstWhere('host', '203.0.113.42');

        return $payload !== null
            && $payload['agent_token_jti'] === 'jti-captured'
            && $payload['agent_token_expires_at'] !== null;
    });
});

it('revokes each server host token during teardown', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $expiresAtOne = now()->addDay();
    $expiresAtTwo = now()->addDays(2);

    $fluxClient = Mockery::mock(FluxClient::class, function (MockInterface $mock) use ($expiresAtOne, $expiresAtTwo): void {
        $mock->shouldReceive('revokeFirewallRule')->andReturn('Firewall rule revoked.');
        $mock->shouldReceive('revokeToken')->once()->with('jti-1', $expiresAtOne->timestamp);
        $mock->shouldReceive('revokeToken')->once()->with('jti-2', $expiresAtTwo->timestamp);
    });
    app()->instance(FluxClient::class, $fluxClient);

    // last_bootstrapped_at null so only the explicit token revocation runs (no
    // SSH marker removal, which would revoke a second time).
    $serverOne = teardownServerPayload([
        'id' => 1,
        'host' => '203.0.113.10',
        'last_bootstrapped_at' => null,
        'agent_token_jti' => 'jti-1',
        'agent_token_expires_at' => $expiresAtOne->toISOString(),
    ]);
    $serverTwo = teardownServerPayload([
        'id' => 2,
        'host' => '203.0.113.11',
        'last_bootstrapped_at' => null,
        'agent_token_jti' => 'jti-2',
        'agent_token_expires_at' => $expiresAtTwo->toISOString(),
    ]);

    (new V5TeardownTeamJob($this->team->id, [$serverOne, $serverTwo]))->handle();

    expect(RevokedAgentToken::query()->where('jti', 'jti-1')->exists())->toBeTrue()
        ->and(RevokedAgentToken::query()->where('jti', 'jti-2')->exists())->toBeTrue();
});

it('logs the incomplete-teardown error naming hosts that could not be torn down', function () {
    Process::fake(['*' => Process::result(output: '')]);
    Log::spy();

    // The ingress host's Flux stop throws (unreachable) so it is reported
    // incomplete; the healthy host tears down cleanly.
    $fluxClient = Mockery::mock(FluxClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('revokeFirewallRule')->andReturn('Firewall rule revoked.');
        $mock->shouldReceive('revokeToken')->andReturnNull();
        $mock->shouldReceive('stopIngress')
            ->with(Mockery::type('string'), 'caddy')
            ->andThrow(new RuntimeException('flux socket unreachable'));
    });
    app()->instance(FluxClient::class, $fluxClient);

    $unreachableIngress = teardownServerPayload([
        'id' => 1,
        'host' => '203.0.113.10',
        'wireguard_management_ip' => '100.64.0.10',
        'is_ingress' => true,
        'ingress_type' => 'caddy',
    ]);
    $healthyServer = teardownServerPayload([
        'id' => 2,
        'host' => '203.0.113.11',
        'wireguard_management_ip' => '100.64.0.11',
    ]);

    (new V5TeardownTeamJob($this->team->id, [$unreachableIngress, $healthyServer]))->handle();

    // The healthy host was still cleaned up despite the ingress host failing.
    Process::assertRan(fn ($process) => is_array($process->command)
        && in_array('root@203.0.113.11', $process->command, true)
        && str_contains((string) end($process->command), '/etc/coolify/v5-node.json'));

    // The operator-facing signal names only the unreachable host.
    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context = []) => str_contains($message, 'v5 team teardown incomplete')
            && ($context['team_id'] ?? null) === $this->team->id
            && collect($context['hosts'] ?? [])->contains(fn ($host) => ($host['host'] ?? null) === '203.0.113.10')
            && collect($context['hosts'] ?? [])->doesntContain(fn ($host) => ($host['host'] ?? null) === '203.0.113.11')
    )->once();
});

it('does not remove the bootstrap marker for servers that were never bootstrapped', function () {
    Process::fake(['*' => Process::result(output: '')]);

    $payload = teardownServerPayload([
        'last_bootstrapped_at' => null,
        'is_ingress' => false,
    ]);

    (new V5TeardownTeamJob($this->team->id, [$payload]))->handle();

    Process::assertNothingRan();
});
