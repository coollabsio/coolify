<?php

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    $this->stack = seedBrowserResourceStack();
});

it('opens the deploy canvas for the environment from its New resource page', function () {
    $environmentPath = "/project/{$this->stack['project']->uuid}/environment/{$this->stack['environment']->uuid}";
    loginAndSkipBoarding();

    $page = visit("{$environmentPath}/new");

    $page->assertSee('Choose a resource')
        ->click('a:visible:has-text("Deploy canvas")')
        ->wait(1)
        ->assertPathIs('/canvas')
        ->assertSee('Deploy canvas')
        ->assertSee('Browser Project')
        ->assertSee('production')
        ->assertSee('localhost')
        ->assertDontSee('API token')
        ->wait(0.5);

    expect($page->script("document.querySelector('.cv-back').getAttribute('href')"))->toBe($environmentPath);

    // The Server step is there, connected to Start, with the team's only server selected.
    $flow = $page->script("JSON.parse(localStorage.getItem('coolify-canvas:flow'))");
    expect($flow['edges'])->toContain(['from' => 'start', 'to' => 'server'])
        ->and(collect($flow['nodes'])->firstWhere('id', 'server')['data']['server'] ?? null)->toBe($this->stack['server']->uuid);
    $page->screenshot(filename: 'deploy-canvas');
});

it('adds a project and an environment from the Start step', function () {
    loginAndSkipBoarding();

    $page = visit('/canvas');

    $page->assertSee('Deploy canvas')
        ->click('.cv-node[data-id="start"] .cv-field:nth-child(1) .cv-select')
        ->click('.cv-dd-new')
        ->fill('.cv-dd-form input', 'Shop')
        ->keys('.cv-dd-form input', 'Enter')
        ->wait(1.5);

    $project = Project::query()->where('name', 'Shop')->firstOrFail();
    $start = fn () => collect($page->script("JSON.parse(localStorage.getItem('coolify-canvas:flow'))")['nodes'])->firstWhere('id', 'start')['data'];
    expect($start())->toBe(['project' => $project->uuid, 'environment' => $project->environments()->value('uuid')]);

    $page->click('.cv-node[data-id="start"] .cv-field:nth-child(2) .cv-select')
        ->click('.cv-dd-new')
        ->fill('.cv-dd-form input', 'staging')
        ->keys('.cv-dd-form input', 'Enter')
        ->wait(1.5)
        ->assertSee('staging')
        ->screenshot(filename: 'deploy-canvas-add-environment');

    expect($start()['environment'])->toBe($project->environments()->where('name', 'staging')->value('uuid'));
});

it('picks a one-click service template from a searchable list', function () {
    loginAndSkipBoarding();

    $page = visit('/canvas');

    $page->assertSee('Deploy canvas')
        ->click('.cv-palette .cv-item:has-text("Service")')
        ->click('.cv-node:has(b:text-is("Service")) .cv-select')
        ->fill('.cv-dd-search', 'uptime kuma')
        ->assertSee('Uptime Kuma With Mariadb')
        ->keys('.cv-dd-search', 'Enter')
        ->assertSee('Uptime Kuma')
        ->wait(0.5)
        ->screenshot(filename: 'deploy-canvas-service-template');

    $flow = $page->script("JSON.parse(localStorage.getItem('coolify-canvas:flow'))");
    expect(collect($flow['nodes'])->firstWhere('type', 'service')['data']['type'] ?? null)->toBe('uptime-kuma');
});

it('shows a closable not-optimized-for-mobile notice only on small screens', function () {
    $project = $this->stack['project']->uuid;
    $environment = $this->stack['environment']->uuid;
    loginAndSkipBoarding();

    $page = visit("/canvas?project={$project}&environment={$environment}");

    $page->assertSee('Deploy canvas')
        ->assertDontSee('The canvas is not optimized for mobile yet.')
        ->resize(390, 844)
        ->assertSee('The canvas is not optimized for mobile yet.')
        ->screenshot(filename: 'deploy-canvas-mobile-notice');

    expect($page->script("document.querySelector('.cv-notice a').getAttribute('href')"))
        ->toBe("/project/{$project}/environment/{$environment}/new");

    $page->click('[aria-label="Close this notice"]')
        ->assertDontSee('The canvas is not optimized for mobile yet.')
        ->refresh()
        ->assertSee('Deploy canvas')
        ->assertDontSee('The canvas is not optimized for mobile yet.')
        ->screenshot(filename: 'deploy-canvas-mobile-notice-closed');
});

it('zooms around the fingers with a two-finger pinch', function () {
    loginAndSkipBoarding();

    $page = visit('/canvas');
    $page->assertSee('Deploy canvas')->wait(0.5);

    // Two touch pointers 100px apart move to 200px apart: the zoom doubles from 100% to the 200% maximum.
    $zoom = $page->script(<<<'JS'
        (async () => {
            const viewport = document.querySelector('.cv-viewport');
            const r = viewport.getBoundingClientRect();
            const y = r.top + r.height / 2;
            const cx = r.left + r.width / 2;
            const fire = (type, id, x) => viewport.dispatchEvent(new PointerEvent(type, { pointerId: id, pointerType: 'touch', isPrimary: id === 1, clientX: x, clientY: y, button: 0, bubbles: true, cancelable: true }));
            document.querySelector('button[title="Reset zoom to 100%"]').click();
            fire('pointerdown', 1, cx - 50);
            fire('pointerdown', 2, cx + 50);
            fire('pointermove', 1, cx - 100);
            fire('pointermove', 2, cx + 100);
            fire('pointerup', 1, cx - 100);
            fire('pointerup', 2, cx + 100);
            // The label is reactive: wait for Svelte to write it.
            await new Promise(resolve => requestAnimationFrame(resolve));
            return document.querySelector('button[title="Reset zoom to 100%"]').textContent;
        })()
    JS);

    expect($zoom)->toBe('200%');
    $page->screenshot(filename: 'deploy-canvas-pinch');
});

it('starts with an empty canvas after a successful deploy', function (bool $deployed, int $expectedNodes) {
    loginAndSkipBoarding();

    $page = visit('/canvas');

    $page->assertSee('Deploy canvas');

    $flow = json_encode(['nodes' => [
        ['id' => 'start', 'type' => 'start', 'x' => 80, 'y' => 160, 'data' => []],
        ['id' => 'server', 'type' => 'server', 'x' => 456, 'y' => 160, 'data' => []],
        ['id' => 'image-1', 'type' => 'image', 'x' => 832, 'y' => 160, 'data' => ['image' => 'nginx']],
        ['id' => 'deploy', 'type' => 'deploy', 'x' => 1240, 'y' => 160, 'data' => []],
    ], 'edges' => []]);
    $page->script("localStorage.setItem('coolify-canvas:flow', JSON.stringify({$flow})); localStorage.setItem('coolify-canvas:deployed', '".json_encode($deployed)."');");

    $page->navigate('/canvas')
        ->assertSee('Deploy canvas');

    expect($page->script("document.querySelectorAll('.cv-node').length"))->toBe($expectedNodes);
    $page->screenshot(filename: 'deploy-canvas-after-deploy');
})->with([
    'after a deploy' => [true, 3],
    'without a deploy' => [false, 4],
]);
