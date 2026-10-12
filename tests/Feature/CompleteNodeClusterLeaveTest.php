<?php

use App\Actions\Node\LeaveNodeClusterNetwork;
use App\Enums\NodeOperationStatus;
use App\Jobs\CompleteNodeClusterLeaveJob;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('constants.flux.internal_url', 'http://flux.internal');
    config()->set('constants.flux.internal_token', 'internal-token');
    $this->pendingLeave = [
        'interface' => 'coolify0',
        'owner_node_ip' => '10.240.0.9',
        'workload_cidrs' => ['100.64.0.0/24'],
    ];
    $team = User::factory()->create()->teams()->firstOrFail();
    $this->node = Node::factory()->create([
        'team_id' => $team->id,
        'is_usable' => true,
        'network_pending_leave' => $this->pendingLeave,
    ]);
    $this->connect = fn (array $capabilities = ['network.cluster.leave.v1']) => Cache::put($this->node->cacheKey(), [
        'status' => 'connected',
        'last_heartbeat_at' => now()->toIso8601String(),
        'capabilities' => $capabilities,
    ]);
    ($this->connect)();
});

function fakeLeaveResult(array $overrides = []): void
{
    Http::fake(fn (Request $request) => Http::response([
        'command_id' => $request->data()['command_id'],
        'observed_at_unix_ms' => 1_700_000_000_000,
        'wireguard_removed' => true,
        'firewall_removed' => true,
        'discovery_removed' => true,
        'resolver_reverted' => true,
        ...$overrides,
    ]));
}

it('sends the deferred leave and clears the pending leave', function () {
    fakeLeaveResult();

    (new CompleteNodeClusterLeaveJob($this->node->id))->handle();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'http://flux.internal/v1/commands/network.cluster.leave'
        && $request->hasHeader('Authorization', 'Bearer internal-token')
        && $request['server_id'] === $this->node->uuid
        && $request['interface'] === 'coolify0'
        && $request['owner_node_ip'] === '10.240.0.9'
        && $request['workload_cidrs'] === ['100.64.0.0/24']);
    $operation = NodeOperation::query()->sole();
    expect($this->node->refresh()->network_pending_leave)->toBeNull()
        ->and($operation->command_type)->toBe('network.cluster.leave.v1')
        ->and($operation->status)->toBe(NodeOperationStatus::SUCCEEDED)
        ->and($operation->dispatched_at)->not->toBeNull()
        ->and($operation->request)->toBe($this->pendingLeave);
});

it('keeps the pending leave for a retry when Flux fails', function () {
    Http::fake(['*' => Http::response(['message' => 'Sentinel failed'], 502)]);

    expect(fn () => (new CompleteNodeClusterLeaveJob($this->node->id))->handle())->toThrow(RequestException::class);

    $operation = NodeOperation::query()->sole();
    expect($this->node->refresh()->network_pending_leave)->toBe($this->pendingLeave)
        ->and($operation->status)->toBe(NodeOperationStatus::FAILED)
        ->and($operation->error)->not->toBeEmpty();
});

it('keeps the pending leave when Flux reports an incomplete cleanup', function (string $field) {
    fakeLeaveResult([$field => false]);

    expect(fn () => (new CompleteNodeClusterLeaveJob($this->node->id))->handle())
        ->toThrow(RuntimeException::class, 'Flux returned an invalid server cleanup result.');

    expect($this->node->refresh()->network_pending_leave)->toBe($this->pendingLeave)
        ->and(NodeOperation::query()->sole()->status)->toBe(NodeOperationStatus::FAILED);
})->with(['wireguard_removed', 'firewall_removed', 'discovery_removed', 'resolver_reverted']);

it('keeps the pending leave when the result belongs to another command', function () {
    fakeLeaveResult(['command_id' => 'another-command']);

    expect(fn () => LeaveNodeClusterNetwork::completePending($this->node))->toThrow(RuntimeException::class);

    expect($this->node->refresh()->network_pending_leave)->toBe($this->pendingLeave);
});

it('waits while the Node is offline', function () {
    Http::fake();
    Cache::forget($this->node->cacheKey());

    expect(LeaveNodeClusterNetwork::completePending($this->node))->toBeFalse();

    Http::assertNothingSent();
    expect($this->node->refresh()->network_pending_leave)->toBe($this->pendingLeave)
        ->and(NodeOperation::query()->count())->toBe(0);
});

it('waits while another leave holds the lock', function () {
    Http::fake();
    $lock = Cache::lock("node-network-leave:{$this->node->id}", 300);
    $lock->get();

    expect(LeaveNodeClusterNetwork::completePending($this->node))->toBeFalse();

    $lock->release();
    Http::assertNothingSent();
    expect($this->node->refresh()->network_pending_leave)->toBe($this->pendingLeave);
});

it('refuses a Node whose Sentinel cannot leave and creates no operation', function () {
    Http::fake();
    ($this->connect)(['system.ping.v1']);

    expect(fn () => LeaveNodeClusterNetwork::completePending($this->node))
        ->toThrow(RuntimeException::class, 'network.cluster.leave.v1');

    Http::assertNothingSent();
    expect($this->node->refresh()->network_pending_leave)->toBe($this->pendingLeave)
        ->and(NodeOperation::query()->count())->toBe(0);
});

it('does nothing when no leave is pending or the Node is gone', function () {
    Http::fake();
    $this->node->update(['network_pending_leave' => null]);

    expect(LeaveNodeClusterNetwork::completePending($this->node))->toBeTrue();
    (new CompleteNodeClusterLeaveJob($this->node->id + 1000))->handle();

    Http::assertNothingSent();
});

it('is unique per Node', function () {
    expect((new CompleteNodeClusterLeaveJob($this->node->id))->uniqueId())->toBe((string) $this->node->id);
});
