<?php

use App\Actions\Node\ConfigureSentinelMetrics;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

it('renders node history charts and changes the time range', function () {
    $stack = seedBrowserResourceStack();
    config(['app.env' => 'local', 'app.maintenance.store' => 'array']);
    setNodesEnabled(true);
    $node = Node::factory()->create(['team_id' => 0, 'private_key_id' => $stack['privateKey']->id]);
    Process::fake(fn () => Process::result(implode("\x1e", [
        '[{"time":1793600000000,"percent":12.5},{"time":1793600030000,"percent":20}]',
        '[{"time":1793600000000,"usedPercent":40}]',
        '[{"time":1793600000000,"mount":"/","usedPercent":70}]',
        '[{"time":1793600000000,"load1":0.5}]',
        '[{"time":1793600000000,"rxBytesPerSec":100,"txBytesPerSec":200}]',
    ])));
    ConfigureSentinelMetrics::shouldRun()->twice()->andReturnNull();
    loginAndSkipBoarding();
    $page = visit('/cluster-server/'.$node->uuid.'/metrics')
        ->assertSee('CPU usage')->assertSee('Network throughput')
        ->wait(2);
    expect($page->script("document.querySelectorAll('.apexcharts-canvas').length"))->toBe(5);
    $page->click('Last 5 minutes')->click('Last hour')->wait(1)
        ->assertSee('Last hour')->click('Live')->assertSee('Paused')
        ->click('Disable metrics')->assertSee('Metrics are disabled')
        ->click('Enable metrics')->assertSee('CPU usage')->wait(1)->screenshot();
    expect($page->script("document.querySelectorAll('.apexcharts-canvas').length"))->toBe(5);
});
