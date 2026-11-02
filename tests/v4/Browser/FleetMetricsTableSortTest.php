<?php

use App\Models\Server;
use App\Services\SentinelMetricsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

class FakeBrowserFleetMetricsClient extends SentinelMetricsClient
{
    public function summary(): ?array
    {
        $usage = [
            'alpha' => ['cpu' => 10.0, 'memUsed' => 80],
            // bravo runs an older Sentinel that does not send the load.
            'bravo' => ['cpu' => 90.0, 'memUsed' => 20, 'load1' => null],
        ][$this->server->name] ?? null;

        // charlie does not answer.
        if ($usage === null) {
            return null;
        }

        return array_merge([
            'online' => true, 'memTotal' => 100,
            'diskUsed' => 10, 'diskTotal' => 100, 'diskPercent' => 10.0,
            'load1' => 0.4, 'netRx' => 5.0, 'netTx' => 1.0,
        ], $usage);
    }

    public function containersCurrent(): array
    {
        return match ($this->server->name) {
            'alpha' => [['id' => 'zeta', 'cpu' => 70.0, 'memUsed' => 10, 'memPercent' => 1.0, 'diskBytes' => 1, 'net' => 30.0]],
            'bravo' => [['id' => 'beta', 'cpu' => 5.0, 'memUsed' => 20, 'memPercent' => 2.0, 'diskBytes' => 2, 'net' => 10.0]],
            default => [],
        };
    }

    public function history(string $metric, string $range): array
    {
        return [];
    }

    public function networkHistory(string $range): array
    {
        return ['rx' => [], 'tx' => []];
    }
}

beforeEach(function () {
    $this->stack = seedBrowserResourceStack(['serverName' => 'alpha']);
    Concurrency::setDefaultInstance('sync');
    // The dashboard chart reads metrics over SSH for each server with metrics on.
    Process::fake();

    $servers = [$this->stack['server']];
    foreach (['bravo', 'charlie'] as $name) {
        $servers[] = Server::factory()->create([
            'name' => $name, 'team_id' => 0, 'private_key_id' => $this->stack['privateKey']->id,
        ]);
    }
    foreach ($servers as $server) {
        // Saved quietly, so the settings hook does not start Sentinel.
        $server->settings->forceFill(['is_metrics_enabled' => true, 'is_reachable' => true, 'is_usable' => true])->saveQuietly();
    }

    app()->bind(SentinelMetricsClient::class, fn ($app, array $parameters) => new FakeBrowserFleetMetricsClient($parameters['server']));
});

/** The visible row names of one metrics table, in DOM order (the order focus and screen readers follow). */
function visibleFleetMetricsRowNames(mixed $page, string $section): array
{
    return $page->script(<<<JS
        [...document.querySelectorAll('#{$section} [data-row]')]
            .filter((row) => getComputedStyle(row).display !== 'none')
            .map((row) => JSON.parse(row.dataset.row).name)
    JS);
}

it('sorts the servers and containers tables when a column header is selected', function () {
    loginAndSkipBoarding();

    $page = visit('/metrics');

    // Default: CPU, largest first. The offline server is last.
    $page->assertSee('charlie');
    expect(visibleFleetMetricsRowNames($page, 'metrics-servers-section'))->toBe(['bravo', 'alpha', 'charlie']);

    $page->click('#metrics-servers-section button[title="Sort by server"]');
    expect(visibleFleetMetricsRowNames($page, 'metrics-servers-section'))->toBe(['alpha', 'bravo', 'charlie']);

    $page->click('#metrics-servers-section button[title="Sort by memory"]');
    expect(visibleFleetMetricsRowNames($page, 'metrics-servers-section'))->toBe(['alpha', 'bravo', 'charlie']);

    // A second click reverses the direction. The offline server stays last.
    $page->click('#metrics-servers-section button[title="Sort by memory"]');
    expect(visibleFleetMetricsRowNames($page, 'metrics-servers-section'))->toBe(['bravo', 'alpha', 'charlie']);

    // A server without a value stays after the servers with one, in both directions.
    $page->click('#metrics-servers-section button[title="Sort by load"]');
    expect(visibleFleetMetricsRowNames($page, 'metrics-servers-section'))->toBe(['alpha', 'bravo', 'charlie']);
    $page->click('#metrics-servers-section button[title="Sort by load"]');
    expect(visibleFleetMetricsRowNames($page, 'metrics-servers-section'))->toBe(['alpha', 'bravo', 'charlie']);

    $page->click('#metrics-servers-section button[title="Sort by memory"]');
    $page->click('#metrics-servers-section button[title="Sort by memory"]');
    expect(visibleFleetMetricsRowNames($page, 'metrics-servers-section'))->toBe(['bravo', 'alpha', 'charlie']);

    // A data refresh keeps the sort.
    $page->click('button:visible:has-text("Last 24 hours")')
        ->click('[role="option"]:has-text("Last 7 days")')
        ->assertQueryStringHas('range', '7d');
    expect(visibleFleetMetricsRowNames($page, 'metrics-servers-section'))->toBe(['bravo', 'alpha', 'charlie']);

    expect(visibleFleetMetricsRowNames($page, 'metrics-containers-section'))->toBe(['zeta', 'beta']);
    // The notification banner at the bottom of the page covers this header, so a pointer click cannot reach it.
    $page->script('document.querySelector(\'#metrics-containers-section button[title="Sort by container"]\').click()');
    expect(visibleFleetMetricsRowNames($page, 'metrics-containers-section'))->toBe(['beta', 'zeta']);

    foreach (['memory' => ['beta', 'zeta'], 'disk' => ['beta', 'zeta'], 'network' => ['zeta', 'beta'], 'cpu' => ['zeta', 'beta']] as $metric => $expected) {
        $page->script("document.querySelector('#metrics-containers-section button[title=\"Sort by {$metric}\"]').click()");
        expect(visibleFleetMetricsRowNames($page, 'metrics-containers-section'))->toBe($expected);

        $page->script("document.querySelector('#metrics-containers-section button[title=\"Sort by {$metric}\"]').click()");
        expect(visibleFleetMetricsRowNames($page, 'metrics-containers-section'))->toBe(array_reverse($expected));
    }

    $page->assertNoJavaScriptErrors()
        ->screenshot(filename: 'fleet-metrics-tables-sorted');
});

it('enables metrics from the empty state', function () {
    Queue::fake();
    Server::query()->each(fn (Server $server) => $server->settings->forceFill(['is_metrics_enabled' => false])->saveQuietly());

    loginAndSkipBoarding();

    $page = visit('/metrics');

    $page->assertSee('No server metrics yet')
        ->click('button:visible:has-text("Enable metrics on 3 servers")')
        ->assertSee('Hottest containers')
        ->assertDontSee('No server metrics yet')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'fleet-metrics-enabled-from-empty-state');
});
