<?php

use App\Actions\Node\ConfigureSentinelMetrics;
use App\Livewire\Node\Metrics;
use App\Models\InstanceSettings;
use App\Models\Node;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    config(['app.env' => 'local', 'constants.ssh.mux_enabled' => false]);
    setNodesEnabled(true);
    $this->owner = User::factory()->create();
    $this->team = $this->owner->teams()->firstOrFail();
    $this->node = Node::factory()->create(['team_id' => $this->team->id]);
    $this->actingAs($this->owner);
    session(['currentTeam' => $this->team]);
});

it('loads historical chart series and rejects unsupported ranges', function () {
    Process::fake(fn () => Process::result(implode("\x1e", [
        '[{"time":1000,"percent":12.5}]',
        '[{"time":1000,"usedPercent":40}]',
        '[{"time":1000,"mount":"/","usedPercent":70}]',
        '[{"time":1000,"load1":0.5}]',
        '[{"time":1000,"rxBytesPerSec":100,"txBytesPerSec":200}]',
    ])));
    Livewire::test(Metrics::class, ['node_uuid' => $this->node->uuid])
        ->call('loadData')
        ->assertDispatched('refreshChartData-node-metrics', fn ($event, $params) => $params[0]['cpuSeries'] == [[1000, 12.5]]
            && $params[0]['networkTxSeries'] == [[1000, 200.0]])
        ->set('interval', 999999)->call('loadData')->assertHasErrors('interval');
});

it('allows member reads but denies collection changes even with another owned team selected', function () {
    $member = User::factory()->create();
    $member->teams()->attach($this->team, ['role' => 'member']);
    $this->actingAs($member);
    session(['currentTeam' => $this->team]);
    $component = Livewire::test(Metrics::class, ['node_uuid' => $this->node->uuid]);
    session(['currentTeam' => $member->teams()->firstOrFail()]);
    Process::fake(fn () => Process::result(implode("\x1e", array_fill(0, 5, '[]'))));
    $component->call('loadData')->assertDispatched('refreshChartData-node-metrics');
    $component->call('toggleMetrics')->assertForbidden();
});

it('denies mounting nodes from another team', function () {
    $other = Node::factory()->create();
    expect(fn () => Livewire::test(Metrics::class, ['node_uuid' => $other->uuid]))
        ->toThrow(ModelNotFoundException::class);
});

it('toggles collection and persists the setting only after Sentinel accepts it', function () {
    ConfigureSentinelMetrics::shouldRun()->once()
        ->withArgs(fn (Node $node, bool $enabled) => $node->id === $this->node->id && ! $enabled)
        ->andReturn('ok');
    $component = Livewire::test(Metrics::class, ['node_uuid' => $this->node->uuid]);
    $component->call('toggleMetrics');
    expect($this->node->fresh()->isMetricsEnabled())->toBeFalse();
    Process::fake();
    $component->call('loadData');
    Process::assertNothingRan();

    ConfigureSentinelMetrics::shouldRun()->once()->andThrow(new RuntimeException('restart failed'));
    $component->call('toggleMetrics')->assertDispatched('error');
    expect($this->node->fresh()->isMetricsEnabled())->toBeFalse();
});

it('reenables metrics without changing unrelated node settings', function () {
    $this->node->update(['settings' => ['is_metrics_enabled' => false, 'connection_timeout' => 30]]);
    ConfigureSentinelMetrics::shouldRun()->once()->withArgs(fn (Node $node, bool $enabled) => $enabled)->andReturnNull();
    Livewire::test(Metrics::class, ['node_uuid' => $this->node->uuid])->call('toggleMetrics')->assertRedirect(route('node.metrics', ['node_uuid' => $this->node->uuid]));
    expect($this->node->fresh()->isMetricsEnabled())->toBeTrue()
        ->and($this->node->fresh()->settings['connection_timeout'])->toBe(30);
});
