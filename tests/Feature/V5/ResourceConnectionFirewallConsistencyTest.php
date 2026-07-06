<?php

use App\Exceptions\V5\UnsupportedCooldVerb;
use App\Models\Team;
use App\Models\User;
use App\Models\V5\Application as V5Application;
use App\Models\V5\ResourceConnection;
use App\Models\V5\Server as V5Server;
use App\Services\Flux\FluxClient;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Support\V5TestSchema;

beforeEach(function () {
    Config::set('broadcasting.default', 'log');
    Config::set('cache.default', 'array');

    dropConnectionFirewallConsistencyTables();
    createConnectionFirewallConsistencyTables();
});

it('restores db rules and rolls back node firewall when flux fails after the db write', function () {
    Exceptions::fake();

    $context = createConnectionFirewallConsistencyContext();
    ['user' => $user, 'team' => $team, 'source' => $source, 'target' => $target, 'connection' => $connection] = $context;

    $connection->rules()->create([
        'source_resource_type' => $source->getMorphClass(),
        'source_resource_id' => $source->id,
        'target_resource_type' => $target->getMorphClass(),
        'target_resource_id' => $target->id,
        'protocol' => 'tcp',
        'port' => 5432,
    ]);

    $oldRuleId = "v5-resource-connection:{$connection->id}:{$source->id}:{$target->id}:tcp:5432";
    $newRuleId = "v5-resource-connection:{$connection->id}:{$source->id}:{$target->id}:tcp:6379";
    $oldRule = connectionFirewallConsistencyRule($oldRuleId, 5432);
    $newRule = connectionFirewallConsistencyRule($newRuleId, 6379);

    $fluxClient = Mockery::mock(FluxClient::class);
    // Forward sync: revoke the removed old rule, then fail applying the new one.
    $fluxClient->shouldReceive('revokeFirewallRule')->once()->with(Mockery::type('string'), $oldRuleId)->andReturn('Firewall rule removed.');
    $fluxClient->shouldReceive('applyFirewallRule')->once()->with(Mockery::type('string'), $newRule)->andThrow(new RuntimeException('apply firewall rule: nft exited with status 1'));
    // Compensation: revoke the attempted new rule and re-apply the restored old rule.
    $fluxClient->shouldReceive('revokeFirewallRule')->once()->with(Mockery::type('string'), $newRuleId)->andReturn('Firewall rule removed.');
    $fluxClient->shouldReceive('applyFirewallRule')->once()->with(Mockery::type('string'), $oldRule)->andReturn('Firewall rule applied.');
    app()->instance(FluxClient::class, $fluxClient);

    patchConnectionFirewallConsistency($this, $user, $team, $connection, [
        "{$source->uuid}->{$target->uuid}" => [6379],
    ])
        ->assertStatus(502)
        ->assertJsonPath('message', 'Could not sync firewall rules through Flux.')
        ->assertJsonPath('detail', 'The previous rules were restored. Check the server diagnostics and try again.');

    expect($connection->rules()->pluck('port')->all())->toBe([5432]);

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'apply firewall rule: nft exited with status 1');
});

it('keeps committed db rules and succeeds when the node coold lacks firewall support', function () {
    $context = createConnectionFirewallConsistencyContext();
    ['user' => $user, 'team' => $team, 'source' => $source, 'target' => $target, 'connection' => $connection] = $context;

    $connection->rules()->create([
        'source_resource_type' => $source->getMorphClass(),
        'source_resource_id' => $source->id,
        'target_resource_type' => $target->getMorphClass(),
        'target_resource_id' => $target->id,
        'protocol' => 'tcp',
        'port' => 5432,
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('revokeFirewallRule')->once()->andThrow(new UnsupportedCooldVerb('firewall.revoke', 'primitive firewall.revoke is not supported by host'));
    $fluxClient->shouldReceive('applyFirewallRule')->once()->andThrow(new UnsupportedCooldVerb('firewall.allow', 'primitive firewall.allow is not supported by host'));
    app()->instance(FluxClient::class, $fluxClient);

    patchConnectionFirewallConsistency($this, $user, $team, $connection, [
        "{$source->uuid}->{$target->uuid}" => [6379],
    ])
        ->assertSuccessful()
        ->assertJsonPath("connection.portsByDirection.{$source->uuid}->{$target->uuid}.0", '6379');

    expect($connection->rules()->pluck('port')->all())->toBe([6379]);
});

it('still surfaces the original flux error when the firewall rollback also fails', function () {
    Exceptions::fake();
    Log::spy();

    $context = createConnectionFirewallConsistencyContext();
    ['user' => $user, 'team' => $team, 'source' => $source, 'target' => $target, 'connection' => $connection] = $context;

    $connection->rules()->create([
        'source_resource_type' => $source->getMorphClass(),
        'source_resource_id' => $source->id,
        'target_resource_type' => $target->getMorphClass(),
        'target_resource_id' => $target->id,
        'protocol' => 'tcp',
        'port' => 5432,
    ]);

    $oldRuleId = "v5-resource-connection:{$connection->id}:{$source->id}:{$target->id}:tcp:5432";
    $newRuleId = "v5-resource-connection:{$connection->id}:{$source->id}:{$target->id}:tcp:6379";
    $newRule = connectionFirewallConsistencyRule($newRuleId, 6379);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('revokeFirewallRule')->once()->with(Mockery::type('string'), $oldRuleId)->andReturn('Firewall rule removed.');
    $fluxClient->shouldReceive('applyFirewallRule')->once()->with(Mockery::type('string'), $newRule)->andThrow(new RuntimeException('apply firewall rule: nft exited with status 1'));
    $fluxClient->shouldReceive('revokeFirewallRule')->once()->with(Mockery::type('string'), $newRuleId)->andThrow(new RuntimeException('revoke firewall rule: coold connection reset'));
    app()->instance(FluxClient::class, $fluxClient);

    patchConnectionFirewallConsistency($this, $user, $team, $connection, [
        "{$source->uuid}->{$target->uuid}" => [6379],
    ])
        ->assertStatus(502)
        ->assertJsonPath('detail', 'The previous rules were restored. Check the server diagnostics and try again.');

    expect($connection->rules()->pluck('port')->all())->toBe([5432]);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'firewall rollback failed'))
        ->once();
});

it('does not delete the connection when revoking firewall rules fails with a real error', function () {
    Exceptions::fake();

    $context = createConnectionFirewallConsistencyContext();
    ['user' => $user, 'team' => $team, 'source' => $source, 'target' => $target, 'connection' => $connection] = $context;

    $connection->rules()->create([
        'source_resource_type' => $source->getMorphClass(),
        'source_resource_id' => $source->id,
        'target_resource_type' => $target->getMorphClass(),
        'target_resource_id' => $target->id,
        'protocol' => 'tcp',
        'port' => 5432,
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('revokeFirewallRule')->once()->andThrow(new RuntimeException('revoke firewall rule: nft exited with status 1'));
    app()->instance(FluxClient::class, $fluxClient);

    deleteConnectionFirewallConsistency($this, $user, $team, $connection)
        ->assertStatus(502)
        ->assertJsonPath('detail', 'The connection was not deleted. Check the server diagnostics and try again.');

    expect(ResourceConnection::query()->whereKey($connection->id)->exists())->toBeTrue()
        ->and($connection->rules()->count())->toBe(1);

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'revoke firewall rule: nft exited with status 1');
});

it('reports but tolerates an unbuildable firewall snapshot when deleting the connection', function () {
    Exceptions::fake();

    $context = createConnectionFirewallConsistencyContext(sourceServerless: true);
    ['user' => $user, 'team' => $team, 'source' => $source, 'target' => $target, 'connection' => $connection] = $context;

    $connection->rules()->create([
        'source_resource_type' => $source->getMorphClass(),
        'source_resource_id' => $source->id,
        'target_resource_type' => $target->getMorphClass(),
        'target_resource_id' => $target->id,
        'protocol' => 'tcp',
        'port' => 5432,
    ]);

    // The node cannot be addressed without a host id, so no flux call may happen.
    $fluxClient = Mockery::mock(FluxClient::class);
    app()->instance(FluxClient::class, $fluxClient);

    deleteConnectionFirewallConsistency($this, $user, $team, $connection)
        ->assertNoContent();

    expect(ResourceConnection::query()->whereKey($connection->id)->exists())->toBeFalse();

    Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'has no reachable server host id'));
});

it('deletes the connection when the node coold lacks firewall revoke support', function () {
    $context = createConnectionFirewallConsistencyContext();
    ['user' => $user, 'team' => $team, 'source' => $source, 'target' => $target, 'connection' => $connection] = $context;

    $connection->rules()->create([
        'source_resource_type' => $source->getMorphClass(),
        'source_resource_id' => $source->id,
        'target_resource_type' => $target->getMorphClass(),
        'target_resource_id' => $target->id,
        'protocol' => 'tcp',
        'port' => 5432,
    ]);

    $fluxClient = Mockery::mock(FluxClient::class);
    $fluxClient->shouldReceive('revokeFirewallRule')->once()->andThrow(new UnsupportedCooldVerb('firewall.revoke', 'primitive firewall.revoke is not supported by host'));
    app()->instance(FluxClient::class, $fluxClient);

    deleteConnectionFirewallConsistency($this, $user, $team, $connection)
        ->assertNoContent();

    expect(ResourceConnection::query()->whereKey($connection->id)->exists())->toBeFalse();
});

/**
 * @param  array<string, array<int, int>>  $portsByDirection
 */
function patchConnectionFirewallConsistency(mixed $test, User $user, Team $team, ResourceConnection $connection, array $portsByDirection): mixed
{
    return $test
        ->actingAs($user)
        ->withSession(['currentTeam' => $team, '_token' => 'test-csrf-token'])
        ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
        ->patchJson("/v5/resource-connections/{$connection->uuid}", [
            'ports_by_direction' => $portsByDirection,
        ]);
}

function deleteConnectionFirewallConsistency(mixed $test, User $user, Team $team, ResourceConnection $connection): mixed
{
    return $test
        ->actingAs($user)
        ->withSession(['currentTeam' => $team, '_token' => 'test-csrf-token'])
        ->withHeader('X-CSRF-TOKEN', 'test-csrf-token')
        ->deleteJson("/v5/resource-connections/{$connection->uuid}");
}

/**
 * @return array{id: string, namespace: string, src: string, dst: string, proto: string, port: int}
 */
function connectionFirewallConsistencyRule(string $ruleId, int $port): array
{
    return [
        'id' => $ruleId,
        'namespace' => 'default',
        'src' => 'coolify-v5-consistency-api',
        'dst' => 'coolify-v5-consistency-postgres',
        'proto' => 'tcp',
        'port' => $port,
    ];
}

/**
 * @return array{user: User, team: Team, source: V5Application, target: V5Application, connection: ResourceConnection}
 */
function createConnectionFirewallConsistencyContext(bool $sourceServerless = false): array
{
    $user = User::withoutEvents(fn () => User::query()->create([
        'name' => 'Margaret Hamilton',
        'email' => 'margaret@example.com',
        'email_verified_at' => now(),
        'password' => 'password',
    ]));
    $team = Team::withoutEvents(fn () => Team::query()->create([
        'name' => 'V5 Firewall Team',
        'description' => null,
        'personal_team' => false,
        'show_boarding' => false,
    ]));
    $user->teams()->attach($team, ['role' => 'owner']);

    $server = V5Server::query()->create([
        'team_id' => $team->id,
        'created_by_user_id' => $user->id,
        'name' => 'edge-01',
        'host' => '203.0.113.10',
        'ssh_user' => 'root',
        'ssh_port' => 22,
        'status' => 'installed',
        'wireguard_management_ip' => '100.64.0.10',
    ]);

    $source = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => 1,
        'environment_id' => 1,
        'server_id' => $sourceServerless ? null : $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'api',
        'image' => 'docker.io/library/nginx:alpine',
        'container_name' => 'coolify-v5-consistency-api',
        'mesh_namespace' => 'default',
        'status' => 'running',
    ]);
    $target = V5Application::query()->create([
        'team_id' => $team->id,
        'project_id' => 1,
        'environment_id' => 1,
        'server_id' => $server->id,
        'created_by_user_id' => $user->id,
        'name' => 'postgres',
        'image' => 'docker.io/library/postgres:16',
        'container_name' => 'coolify-v5-consistency-postgres',
        'mesh_namespace' => 'default',
        'status' => 'running',
    ]);

    $connection = ResourceConnection::query()->create([
        'team_id' => $team->id,
        'project_id' => 1,
        'environment_id' => 1,
        'resource_one_type' => $source->getMorphClass(),
        'resource_one_id' => $source->id,
        'resource_two_type' => $target->getMorphClass(),
        'resource_two_id' => $target->id,
        'resource_pair_key' => "application:{$source->id}|application:{$target->id}",
        'created_by_user_id' => $user->id,
    ]);

    return [
        'user' => $user,
        'team' => $team,
        'source' => $source,
        'target' => $target,
        'connection' => $connection,
    ];
}

function createConnectionFirewallConsistencyTables(): void
{
    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name')->default('Anonymous');
        $table->string('email');
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });

    Schema::create('teams', function ($table) {
        $table->id();
        $table->string('name');
        $table->string('description')->nullable();
        $table->boolean('personal_team')->default(false);
        $table->boolean('show_boarding')->default(false);
        $table->timestamps();
    });

    Schema::create('team_user', function ($table) {
        $table->id();
        $table->foreignId('team_id');
        $table->foreignId('user_id');
        $table->string('role')->default('member');
        $table->timestamps();

        $table->unique(['team_id', 'user_id']);
    });

    V5TestSchema::createServersTable();
    V5TestSchema::createApplicationsTable();
    V5TestSchema::createResourceConnectionsTable();
    V5TestSchema::createResourceConnectionRulesTable();
}

function dropConnectionFirewallConsistencyTables(): void
{
    Schema::dropIfExists('v5_resource_connection_rules');
    Schema::dropIfExists('v5_resource_connections');
    Schema::dropIfExists('v5_applications');
    Schema::dropIfExists('v5_servers');
    Schema::dropIfExists('team_user');
    Schema::dropIfExists('teams');
    Schema::dropIfExists('users');
}
