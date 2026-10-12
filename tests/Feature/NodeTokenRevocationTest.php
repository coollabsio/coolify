<?php

use App\Actions\Node\InstallSentinel;
use App\Actions\Node\RotateSentinelToken;
use App\Actions\Node\UpgradeSentinel;
use App\Enums\NodeOperationStatus;
use App\Livewire\Node\Show;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    $keyPair = sodium_crypto_sign_seed_keypair(str_repeat('C', 32));
    config()->set('constants.flux.public_url', 'http://flux:7443');
    config()->set('constants.flux.development_allow_plaintext', true);
    config()->set('constants.flux.signing_key_id', 'dev-key');
    config()->set('constants.flux.signing_private_key', base64_encode(sodium_crypto_sign_secretkey($keyPair)));
    config()->set('constants.flux.signing_public_key', base64_encode(sodium_crypto_sign_publickey($keyPair)));
    config()->set('constants.flux.issuer', 'coolify-dev');

    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->firstOrFail();
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->node = Node::factory()->create(['team_id' => $this->team->id]);
    $this->token = $this->node->ensureValidSentinelToken();
});

function requestNodeAssignment(string $token): TestResponse
{
    return test()->postJson('/api/v1/sentinel/control/assignment', [
        'sentinel_version' => 'main',
        'protocol_min' => 1,
        'protocol_max' => 1,
        'capabilities' => ['system.ping.v1'],
    ], ['Authorization' => 'Bearer '.$token]);
}

function actAsNodeTeamMember(): void
{
    $member = User::factory()->create();
    $member->teams()->attach(test()->team, ['role' => 'member']);
    test()->actingAs($member);
    session(['currentTeam' => test()->team]);
}

it('issues tokens with a random nonce that still identify the node', function () {
    $payload = json_decode(decrypt($this->token), true);

    expect($payload['node_uuid'])->toBe($this->node->uuid)
        ->and($payload['nonce'])->toBeString()->toHaveLength(32);
});

it('keeps accepting legacy tokens without a nonce', function () {
    $legacy = encrypt(json_encode(['node_uuid' => $this->node->uuid]));
    $this->node->forceFill(['sentinel_token' => $legacy])->save();

    requestNodeAssignment($legacy)->assertOk();
});

it('rejects the previous token after rotation and pushes the new one to the host', function () {
    InstallSentinel::shouldRun()->once()->andReturnUsing(function (Node $node): string {
        expect($node->sentinel_token)->not->toBe($this->token);

        return 'ok';
    });

    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid])
        ->call('rotateSentinelToken')
        ->assertDispatched('success');

    $rotated = $this->node->fresh()->sentinel_token;
    expect($rotated)->not->toBe($this->token);

    requestNodeAssignment($this->token)->assertUnauthorized();
    requestNodeAssignment($rotated)->assertOk();
});

it('keeps the image of the last successful Sentinel upgrade when rotating the token', function () {
    NodeOperation::factory()->create([
        'node_id' => $this->node->id,
        'command_type' => UpgradeSentinel::COMMAND_TYPE,
        'status' => NodeOperationStatus::SUCCEEDED,
        'request' => ['version' => '1.2.3', 'image' => 'ghcr.io/coollabsio/sentinel-host:1.2.3'],
    ]);
    InstallSentinel::shouldRun()->once()->with(Mockery::any(), 'ghcr.io/coollabsio/sentinel-host:1.2.3')->andReturn('ok');

    RotateSentinelToken::run($this->node);
});

it('restores the previous token when the host cannot receive the new one', function () {
    InstallSentinel::shouldRun()->once()->andThrow(new RuntimeException('ssh failed'));

    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid])
        ->call('rotateSentinelToken')
        ->assertDispatched('error');

    expect($this->node->fresh()->sentinel_token)->toBe($this->token);
    requestNodeAssignment($this->token)->assertOk();
});

it('deletes a standalone node and rejects its token', function () {
    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid])
        ->call('deleteNode')
        ->assertRedirect(route('server.index'));

    expect(Node::query()->whereKey($this->node->id)->exists())->toBeFalse();
    requestNodeAssignment($this->token)->assertUnauthorized();
});

it('refuses to delete a node that is in a cluster', function () {
    $cluster = NodeCluster::factory()->create(['team_id' => $this->team->id]);
    $this->node->forceFill(['node_cluster_id' => $cluster->id])->save();

    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid])
        ->call('deleteNode')
        ->assertDispatched('error');

    expect($this->node->fresh())->not->toBeNull();
});

it('refuses to delete a node that runs workloads', function () {
    $workload = NodeWorkload::factory()->create(['team_id' => $this->team->id]);
    $this->node->workloads()->attach($workload);

    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid])
        ->call('deleteNode')
        ->assertDispatched('error');

    expect($this->node->fresh())->not->toBeNull();
});

it('does not let team members rotate tokens or delete nodes', function () {
    InstallSentinel::shouldRun()->never();
    actAsNodeTeamMember();

    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid])
        ->call('rotateSentinelToken')
        ->assertForbidden();
    Livewire::test(Show::class, ['node_uuid' => $this->node->uuid])
        ->call('deleteNode')
        ->assertForbidden();

    expect($this->node->fresh()->sentinel_token)->toBe($this->token);
});

it('denies admins of another team', function () {
    $outsider = User::factory()->create();
    expect($outsider->can('manageSentinel', $this->node))->toBeFalse()
        ->and($outsider->can('delete', $this->node))->toBeFalse();
});
