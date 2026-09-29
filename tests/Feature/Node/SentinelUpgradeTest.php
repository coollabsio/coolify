<?php

use App\Actions\Node\FetchLatestSentinelRelease;
use App\Actions\Node\InstallSentinel;
use App\Actions\Node\RollbackSentinel;
use App\Actions\Node\UpgradeSentinel;
use App\Enums\NodeOperationStatus;
use App\Jobs\UpgradeAllNodeSentinelsJob;
use App\Jobs\UpgradeNodeSentinelJob;
use App\Livewire\Node\Show;
use App\Livewire\NodeCluster\Index as NodeClusterIndex;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const SENTINEL_UPGRADE_DIGEST = 'sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config()->set('app.env', 'local');
    config()->set('constants.sentinel.host_enabled', true);
    config()->set('constants.sentinel.host_repository', 'ghcr.io/coollabsio/sentinel-host');
    config()->set('constants.coolify.versions_url', 'https://cdn.example/coolify/versions.json');
    Http::fake(['cdn.example/*' => function () {
        $release = app('testing.sentinel-host-release');
        if ($release === 'http-error') {
            return Http::response('', 500);
        }
        $coolify = ['sentinel' => ['version' => '1.0.1']];
        if ($release !== null) {
            $coolify['sentinel-host'] = $release;
        }

        return Http::response(['coolify' => $coolify]);
    }]);
    fakeSentinelRelease(['version' => '1.1.0', 'digest' => SENTINEL_UPGRADE_DIGEST]);
    Sleep::fake();

    $this->user = User::factory()->create();
    $this->team = $this->user->teams()->firstOrFail();
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
    $this->node = Node::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'alpha',
        'is_usable' => true,
        'sentinel_version' => '1.0.2',
    ]);
});

function fakeSentinelRelease(mixed $release): void
{
    Cache::forget(FetchLatestSentinelRelease::CACHE_KEY);
    app()->instance('testing.sentinel-host-release', $release);
}

function reconnectSentinel(Node $node, string $version): void
{
    Cache::put($node->cacheKey(), [
        'status' => 'connected',
        'connection_id' => '22222222-2222-4222-8222-222222222222',
        'sentinel_version' => $version,
        'connected_at' => now()->subDay()->toIso8601String(),
        'last_connected_at' => now()->toIso8601String(),
    ]);
}

describe('release source', function () {
    it('reads a valid sentinel-host release and installs it by digest', function () {
        expect(FetchLatestSentinelRelease::run())->toBe([
            'version' => '1.1.0',
            'digest' => SENTINEL_UPGRADE_DIGEST,
            'image' => 'ghcr.io/coollabsio/sentinel-host@'.SENTINEL_UPGRADE_DIGEST,
        ]);
    });

    it('caches the fetched release', function () {
        FetchLatestSentinelRelease::run();
        FetchLatestSentinelRelease::run();

        Http::assertSentCount(1);
    });

    it('offers no release for invalid or missing entries', function (mixed $release) {
        fakeSentinelRelease($release);

        expect(FetchLatestSentinelRelease::run())->toBeNull()
            ->and($this->node->needsSentinelUpgrade(FetchLatestSentinelRelease::run()))->toBeFalse();
    })->with([
        'missing key' => [null],
        'invalid digest' => [['version' => '1.1.0', 'digest' => 'sha256:abc']],
        'uppercase digest' => [['version' => '1.1.0', 'digest' => strtoupper(SENTINEL_UPGRADE_DIGEST)]],
        'digest with injection' => [['version' => '1.1.0', 'digest' => SENTINEL_UPGRADE_DIGEST.';reboot']],
        'non-semver version' => [['version' => 'main', 'digest' => SENTINEL_UPGRADE_DIGEST]],
        'prerelease version' => [['version' => '1.1.0-rc.1', 'digest' => SENTINEL_UPGRADE_DIGEST]],
        'version with prefix' => [['version' => 'v1.1.0', 'digest' => SENTINEL_UPGRADE_DIGEST]],
        'not an object' => ['1.1.0'],
    ]);

    it('offers no release when versions.json cannot be fetched', function () {
        fakeSentinelRelease('http-error');

        expect(FetchLatestSentinelRelease::run())->toBeNull();
    });

    it('leaves the legacy sentinel version helper untouched', function () {
        expect(get_latest_sentinel_version())->toBe('1.0.1');
    });
});

it('decides whether an upgrade is available', function (?string $running, bool $available) {
    $release = ['version' => '1.1.0', 'digest' => SENTINEL_UPGRADE_DIGEST];

    expect(FetchLatestSentinelRelease::isUpgradeAvailable($running, $release))->toBe($available);
})->with([
    'older' => ['1.0.2', true],
    'older major' => ['0.9.9', true],
    'same' => ['1.1.0', false],
    'newer' => ['1.2.0', false],
    'development branch' => ['main', true],
    'development build' => ['1.0.2-dev+abc', true],
    'prerelease of latest' => ['1.1.0-rc.1', true],
    'unknown' => [null, false],
]);

it('stores the Sentinel version from a connected event', function () {
    config()->set('constants.flux.internal_token', 'internal-secret');
    config()->set('constants.flux.public_url', 'http://flux:7443');
    config()->set('constants.flux.development_allow_plaintext', true);

    $this->postJson('/api/v1/internal/sentinel/control/events', [
        'event' => 'connected',
        'server_id' => $this->node->uuid,
        'connection_id' => '11111111-1111-4111-8111-111111111111',
        'sentinel_version' => '1.1.0',
        'protocol_version' => 1,
        'trust_bundle_version' => 1,
        'transport' => 'plaintext',
    ], ['Authorization' => 'Bearer internal-secret'])->assertNoContent();

    expect($this->node->refresh()->sentinel_version)->toBe('1.1.0')
        ->and(data_get(Cache::get($this->node->cacheKey()), 'last_connected_at'))->toBe(now()->toIso8601String());
});

it('upgrades Sentinel by digest and succeeds after it reconnects with the new version', function () {
    $this->freezeSecond();
    InstallSentinel::partialMock()->shouldReceive('handle')->once()
        ->withArgs(fn (Node $node, string $image) => $node->is($this->node) && $image === 'ghcr.io/coollabsio/sentinel-host@'.SENTINEL_UPGRADE_DIGEST)
        ->andReturnUsing(function (Node $node) {
            reconnectSentinel($node, '1.1.0');

            return '';
        });
    RollbackSentinel::partialMock()->shouldNotReceive('handle');

    $operation = UpgradeSentinel::make()->start($this->node, $this->user);
    (new UpgradeNodeSentinelJob($operation->id))->handle();

    $operation->refresh();
    expect($operation->command_type)->toBe('sentinel.upgrade.v1')
        ->and($operation->status)->toBe(NodeOperationStatus::SUCCEEDED)
        ->and($operation->request)->toMatchArray(['version' => '1.1.0', 'previous_version' => '1.0.2'])
        ->and($operation->result)->toMatchArray(['version' => '1.1.0', 'previous_version' => '1.0.2'])
        ->and($this->node->refresh()->sentinel_version)->toBe('1.1.0')
        ->and(NodeOperation::query()->userFacing()->whereKey($operation->id)->exists())->toBeTrue();
});

it('restores the previous Sentinel when the new version does not reconnect', function (?array $connection) {
    InstallSentinel::partialMock()->shouldReceive('handle')->once()->andReturnUsing(function (Node $node) use ($connection) {
        if ($connection !== null) {
            Cache::put($node->cacheKey(), $connection);
        }

        return '';
    });
    RollbackSentinel::partialMock()->shouldReceive('handle')->once()
        ->with(Mockery::on(fn (Node $node) => $node->is($this->node)))
        ->andReturn('');

    $operation = UpgradeSentinel::make()->start($this->node, $this->user);
    UpgradeSentinel::run($operation);

    $operation->refresh();
    expect($operation->status)->toBe(NodeOperationStatus::FAILED)
        ->and($operation->error)->toBe('Sentinel 1.1.0 did not reconnect within 60 seconds. The previous version was restored.')
        ->and($this->node->refresh()->sentinel_version)->toBe('1.0.2');
    Sleep::assertSleptTimes(30);
})->with([
    'no connection' => [null],
    'old version reconnected' => [fn () => [
        'status' => 'connected',
        'sentinel_version' => '1.0.2',
        'last_connected_at' => now()->addMinute()->toIso8601String(),
    ]],
    'expected version but connection is from before the upgrade' => [fn () => [
        'status' => 'connected',
        'sentinel_version' => '1.1.0',
        'last_connected_at' => now()->subHour()->toIso8601String(),
    ]],
    'reconnecting' => [fn () => [
        'status' => 'reconnecting',
        'sentinel_version' => '1.1.0',
        'last_connected_at' => now()->addMinute()->toIso8601String(),
    ]],
]);

it('reports when restoring the previous Sentinel fails', function () {
    InstallSentinel::partialMock()->shouldReceive('handle')->once()->andReturn('');
    RollbackSentinel::partialMock()->shouldReceive('handle')->once()->andThrow(new RuntimeException('No previous Sentinel binary is available to restore.'));

    $operation = UpgradeSentinel::run(UpgradeSentinel::make()->start($this->node, $this->user));

    expect($operation->status)->toBe(NodeOperationStatus::FAILED)
        ->and($operation->error)->toBe('Sentinel 1.1.0 did not reconnect within 60 seconds. Restoring the previous version failed: No previous Sentinel binary is available to restore.');
});

it('fails without a rollback when the installer fails', function () {
    InstallSentinel::partialMock()->shouldReceive('handle')->once()->andThrow(new RuntimeException('pull failed'));
    RollbackSentinel::partialMock()->shouldNotReceive('handle');

    $operation = UpgradeSentinel::run(UpgradeSentinel::make()->start($this->node, $this->user));

    expect($operation->status)->toBe(NodeOperationStatus::FAILED)
        ->and($operation->error)->toBe('Sentinel 1.1.0 could not be installed. The previous version is still installed. pull failed');
});

it('refuses to upgrade while the Node has another active operation', function (NodeOperationStatus $status) {
    NodeOperation::query()->create([
        'node_id' => $this->node->id,
        'command_type' => 'workload.deploy.v1',
        'idempotency_key' => 'active-operation',
        'request' => [],
        'status' => $status,
    ]);
    InstallSentinel::partialMock()->shouldNotReceive('handle');

    expect(fn () => UpgradeSentinel::make()->start($this->node, $this->user))
        ->toThrow(RuntimeException::class, 'Another operation is active on this Node. Wait for it to finish, then upgrade Sentinel.')
        ->and(NodeOperation::query()->where('command_type', 'sentinel.upgrade.v1')->exists())->toBeFalse();
})->with([
    NodeOperationStatus::QUEUED,
    NodeOperationStatus::DISPATCHED,
    NodeOperationStatus::RUNNING,
    NodeOperationStatus::VERIFYING,
    NodeOperationStatus::UNCERTAIN,
]);

it('refuses to upgrade a Node that is already current', function () {
    $this->node->update(['sentinel_version' => '1.1.0']);

    expect(fn () => UpgradeSentinel::make()->start($this->node, $this->user))
        ->toThrow(RuntimeException::class, 'Sentinel on this Node is already up to date.');
});

it('fails the operation when the upgrade worker stops', function () {
    $operation = UpgradeSentinel::make()->start($this->node, $this->user);

    (new UpgradeNodeSentinelJob($operation->id))->failed(new RuntimeException('killed'));

    expect($operation->refresh()->status)->toBe(NodeOperationStatus::FAILED)
        ->and($operation->error)->toBe('The Sentinel upgrade stopped before completion. Check the Sentinel version on the Node.');
});

it('upgrades all Nodes one at a time and stops at the first failure', function () {
    $this->freezeSecond();
    $bravo = Node::factory()->create(['team_id' => $this->team->id, 'name' => 'bravo', 'is_usable' => true, 'sentinel_version' => 'main']);
    $charlie = Node::factory()->create(['team_id' => $this->team->id, 'name' => 'charlie', 'is_usable' => true, 'sentinel_version' => '1.0.0']);
    $current = Node::factory()->create(['team_id' => $this->team->id, 'name' => 'aardvark', 'is_usable' => true, 'sentinel_version' => '1.1.0']);
    $unusable = Node::factory()->create(['team_id' => $this->team->id, 'name' => 'aaa-unusable', 'is_usable' => false, 'sentinel_version' => '1.0.0']);
    $installed = [];
    InstallSentinel::partialMock()->shouldReceive('handle')->twice()->andReturnUsing(function (Node $node) use (&$installed, $bravo) {
        $installed[] = $node->name;
        if (! $node->is($bravo)) {
            reconnectSentinel($node, '1.1.0');
        }

        return '';
    });
    RollbackSentinel::partialMock()->shouldReceive('handle')->once()->andReturn('');

    (new UpgradeAllNodeSentinelsJob($this->team->id, $this->user->id))->handle();

    $summary = Cache::get(UpgradeSentinel::upgradeAllCacheKey($this->team->id));
    expect($installed)->toBe(['alpha', 'bravo'])
        ->and($summary)->toMatchArray([
            'status' => 'failed',
            'upgraded' => [$this->node->uuid],
            'failed_node_uuid' => $bravo->uuid,
            'failed_node_name' => 'bravo',
            'error' => 'Sentinel 1.1.0 did not reconnect within 60 seconds. The previous version was restored.',
        ])
        ->and($this->node->refresh()->sentinel_version)->toBe('1.1.0')
        ->and($charlie->operations()->exists())->toBeFalse()
        ->and($current->operations()->exists())->toBeFalse()
        ->and($unusable->operations()->exists())->toBeFalse();
});

it('stops upgrading all Nodes when one Node has an active operation', function () {
    $bravo = Node::factory()->create(['team_id' => $this->team->id, 'name' => 'bravo', 'is_usable' => true, 'sentinel_version' => '1.0.0']);
    NodeOperation::query()->create([
        'node_id' => $this->node->id,
        'command_type' => 'workload.deploy.v1',
        'idempotency_key' => 'active-operation',
        'request' => [],
        'status' => NodeOperationStatus::RUNNING,
    ]);
    InstallSentinel::partialMock()->shouldNotReceive('handle');

    $summary = UpgradeSentinel::make()->upgradeAll($this->team->id, $this->user);

    expect($summary)->toMatchArray([
        'status' => 'failed',
        'failed_node_uuid' => $this->node->uuid,
        'error' => 'Another operation is active on this Node. Wait for it to finish, then upgrade Sentinel.',
    ])->and($bravo->operations()->exists())->toBeFalse();
});

describe('Livewire', function () {
    it('shows the running and latest version with an upgrade button and queues the upgrade', function () {
        Queue::fake();

        Livewire::test(Show::class, ['node_uuid' => $this->node->uuid, 'section' => 'sentinel'])
            ->assertSee('Running version')
            ->assertSee('1.0.2')
            ->assertSee('1.1.0')
            ->assertSee('Upgrade available')
            ->assertSee('Upgrade Sentinel')
            ->call('upgradeSentinel')
            ->assertDispatched('success', 'Sentinel upgrade queued.')
            ->assertSee('Upgrade queued');

        $operation = NodeOperation::query()->where('command_type', 'sentinel.upgrade.v1')->sole();
        Queue::assertPushed(UpgradeNodeSentinelJob::class, fn ($job) => $job->operationId === $operation->id);
    });

    it('hides the upgrade button when Sentinel is current', function () {
        $this->node->update(['sentinel_version' => '1.1.0']);

        Livewire::test(Show::class, ['node_uuid' => $this->node->uuid, 'section' => 'sentinel'])
            ->assertSee('Up to date')
            ->assertDontSee('Upgrade available')
            ->assertDontSee('Upgrade Sentinel');
    });

    it('shows a clear error and queues nothing while another operation is active', function () {
        Queue::fake();
        NodeOperation::query()->create([
            'node_id' => $this->node->id,
            'command_type' => 'workload.deploy.v1',
            'idempotency_key' => 'active-operation',
            'request' => [],
            'status' => NodeOperationStatus::RUNNING,
        ]);

        Livewire::test(Show::class, ['node_uuid' => $this->node->uuid, 'section' => 'sentinel'])
            ->call('upgradeSentinel')
            ->assertDispatched('error', 'Another operation is active on this Node. Wait for it to finish, then upgrade Sentinel.');

        Queue::assertNothingPushed();
        expect(NodeOperation::query()->where('command_type', 'sentinel.upgrade.v1')->exists())->toBeFalse();
    });

    it('forbids team members from upgrading Sentinel', function () {
        Queue::fake();
        $member = User::factory()->create();
        $member->teams()->attach($this->team->id, ['role' => 'member']);
        $this->actingAs($member);
        session(['currentTeam' => $this->team]);

        Livewire::test(Show::class, ['node_uuid' => $this->node->uuid, 'section' => 'sentinel'])
            ->assertDontSee('Upgrade Sentinel')
            ->call('upgradeSentinel')
            ->assertForbidden();
        Livewire::test(NodeClusterIndex::class)
            ->assertSee('Upgrade available')
            ->assertDontSee('Upgrade all')
            ->call('upgradeAllSentinels')
            ->assertForbidden();

        Queue::assertNothingPushed();
        expect(NodeOperation::query()->exists())->toBeFalse();
    });

    it('does not let another team open or upgrade the Node', function () {
        Queue::fake();
        $other = User::factory()->create();
        $this->actingAs($other);
        session(['currentTeam' => $other->teams()->firstOrFail()]);

        expect(fn () => Livewire::test(Show::class, ['node_uuid' => $this->node->uuid, 'section' => 'sentinel']))
            ->toThrow(ModelNotFoundException::class);
        Livewire::test(NodeClusterIndex::class)
            ->assertDontSee('Upgrade all')
            ->call('upgradeAllSentinels')
            ->assertDispatched('info', 'Sentinel is up to date on every Node.');

        Queue::assertNothingPushed();
    });

    it('queues upgrade all only for the current team and shows the button when an upgrade is available', function () {
        Queue::fake();

        Livewire::test(NodeClusterIndex::class)
            ->assertSee('Upgrade available')
            ->assertSee('Upgrade all')
            ->call('upgradeAllSentinels')
            ->assertDispatched('success', 'Sentinel upgrade queued. Nodes are upgraded one at a time.')
            ->assertDontSee('Upgrade all');

        Queue::assertPushed(UpgradeAllNodeSentinelsJob::class, fn ($job) => $job->teamId === $this->team->id && $job->userId === $this->user->id);
    });

    it('hides upgrade all when no Node needs an upgrade', function () {
        $this->node->update(['sentinel_version' => '1.1.0']);

        Livewire::test(NodeClusterIndex::class)
            ->assertDontSee('Upgrade available')
            ->assertDontSee('Upgrade all');
    });
});
