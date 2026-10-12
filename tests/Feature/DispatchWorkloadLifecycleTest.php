<?php

use App\Actions\Node\DispatchWorkloadLifecycle;
use App\Enums\NodeOperationStatus;
use App\Enums\NodeWorkloadAction;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('constants.flux.internal_url', 'http://flux.internal/');
    config()->set('constants.flux.internal_token', 'internal-token');
    $team = User::factory()->create()->teams()->firstOrFail();
    $this->node = Node::factory()->create(['team_id' => $team->id]);
    $this->workload = NodeWorkload::factory()->create(['team_id' => $team->id]);
    $this->revision = NodeWorkloadRevision::factory()->create(['node_workload_id' => $this->workload->id]);
    $this->containerName = 'coolify-'.$this->workload->uuid.'-main';
    $this->makeOperation = fn (array $attributes = []): NodeOperation => NodeOperation::factory()->create([
        'node_id' => $this->node->id,
        'node_workload_id' => $this->workload->id,
        'node_workload_revision_id' => $this->revision->id,
        'command_type' => 'workload.lifecycle.v1',
        'status' => NodeOperationStatus::RUNNING,
        'request' => ['action' => 'restart'],
        ...$attributes,
    ]);
});

function fakeLifecycleResult(array $overrides = []): void
{
    Http::fake(fn (Request $request) => Http::response([
        'command_id' => $request['command_id'],
        'observed_at_unix_ms' => 1_700_000_000_000,
        'name' => $request['name'],
        'action' => $request['action'],
        ...$overrides,
    ]));
}

it('sends the requested lifecycle action for the managed container', function (NodeWorkloadAction $action) {
    fakeLifecycleResult();
    $operation = ($this->makeOperation)(['request' => ['action' => $action->value]]);

    $result = DispatchWorkloadLifecycle::run($operation);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->url() === 'http://flux.internal/v1/commands/workload.lifecycle'
        && $request->hasHeader('Authorization', 'Bearer internal-token')
        && $request->data() === [
            'server_id' => $this->node->uuid,
            'command_id' => $operation->uuid,
            'name' => $this->containerName,
            'action' => $action->value,
        ]);
    expect($result)->toBe([
        'command_id' => $operation->uuid,
        'observed_at_unix_ms' => 1_700_000_000_000,
        'name' => $this->containerName,
        'action' => $action->value,
    ]);
})->with(NodeWorkloadAction::cases());

it('leaves the operation state to the caller', function () {
    fakeLifecycleResult();
    $operation = ($this->makeOperation)();

    DispatchWorkloadLifecycle::run($operation);

    $operation->refresh();
    expect($operation->status)->toBe(NodeOperationStatus::RUNNING)
        ->and($operation->dispatched_at)->toBeNull();
});

it('refuses an incomplete operation without contacting Flux', function (Closure $attributes) {
    Http::fake();
    $operation = ($this->makeOperation)($attributes->call($this));

    expect(fn () => DispatchWorkloadLifecycle::run($operation))
        ->toThrow(RuntimeException::class, 'The workload lifecycle operation is incomplete.');

    Http::assertNothingSent();
})->with([
    'wrong command type' => fn () => ['command_type' => 'workload.deploy.v1'],
    'unknown action' => fn () => ['request' => ['action' => 'pause']],
    'missing action' => fn () => ['request' => []],
    'missing revision' => fn () => ['node_workload_revision_id' => null],
    'missing workload' => fn () => ['node_workload_id' => null],
]);

it('refuses to send without the Flux internal API configuration', function (string $key) {
    Http::fake();
    config()->set("constants.flux.{$key}", '');

    expect(fn () => DispatchWorkloadLifecycle::run(($this->makeOperation)()))
        ->toThrow(RuntimeException::class, 'Flux internal API configuration is incomplete.');

    Http::assertNothingSent();
})->with(['internal_url', 'internal_token']);

it('surfaces Flux errors', function () {
    Http::fake(['*' => Http::response(['message' => 'Sentinel failed'], 502)]);

    expect(fn () => DispatchWorkloadLifecycle::run(($this->makeOperation)()))->toThrow(RequestException::class);
});

it('rejects a result that does not match the command', function (array $overrides) {
    fakeLifecycleResult($overrides);

    expect(fn () => DispatchWorkloadLifecycle::run(($this->makeOperation)()))
        ->toThrow(RuntimeException::class, 'Flux returned an invalid workload lifecycle result.');
})->with([
    'other command' => [['command_id' => 'another-command']],
    'other container' => [['name' => 'coolify-other-main']],
    'other action' => [['action' => 'stop']],
    'missing observation time' => [['observed_at_unix_ms' => null]],
    'zero observation time' => [['observed_at_unix_ms' => 0]],
]);
