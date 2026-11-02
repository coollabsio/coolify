<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    $this->stack = seedBrowserResourceStack();

    // 50,000 timestamped lines, the maximum the viewer accepts.
    $this->bigLog = implode("\n", array_map(
        fn (int $i) => sprintf('2026-09-28T10:%02d:%02d.%09dZ log line %05d', intdiv($i, 3600) % 60, intdiv($i, 60) % 60, $i, $i),
        range(1, 50000),
    ));

    Process::fake(function (PendingProcess $process) {
        if (str_contains($process->command, '--since')) {
            return Process::result(output: '2026-09-28T23:59:59Z streamed line after since');
        }
        if (str_contains($process->command, 'docker logs')) {
            return Process::result(output: $this->bigLog);
        }

        return Process::result();
    });
});

it('renders 50,000 runtime log lines with only the visible rows in the DOM', function () {
    $page = visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertSee('Dashboard')
        ->navigate("/server/{$this->stack['server']->uuid}/sentinel/logs");

    $page->assertSee('log line 00001');

    expect($page->script('document.querySelectorAll("[data-log-line]").length'))->toBeLessThan(100);

    // Scroll to the end: the last line is rendered, the first line is not.
    $page->script('(() => { const el = document.getElementById("logsContainer"); el.scrollTop = el.scrollHeight; })()');
    $page->wait(0.5)
        ->assertSee('log line 50000')
        ->assertDontSee('log line 00001');

    expect($page->script('document.querySelectorAll("[data-log-line]").length'))->toBeLessThan(100);

    $page->screenshot(filename: 'runtime-logs-virtualized-end');
});

it('searches all loaded lines and highlights matches', function () {
    $page = visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertSee('Dashboard');

    $page->script('localStorage.setItem("coolify-log-text-view", "false")');
    $page->navigate("/server/{$this->stack['server']->uuid}/sentinel/logs")
        ->assertSee('log line 00001')
        ->type('input[aria-label="Find in logs"]', 'line 4999')
        ->wait(0.6)
        ->assertSee('log line 49990')
        ->assertDontSee('log line 00001');

    expect($page->script('document.querySelectorAll(".log-highlight").length'))->toBe(10)
        ->and($page->script('document.querySelector(".log-highlight").textContent'))->toBe('line 4999');

    // Row details still open inside the virtual list.
    $page->click('log line 49990')
        ->wait(0.3);

    expect($page->script('document.querySelectorAll(".runtime-log-detail").length'))->toBe(1)
        ->and($page->script('document.querySelector(".runtime-log-detail").textContent'))->toBe('log line 49990');

    $page->screenshot(filename: 'runtime-logs-virtualized-search');
});

it('moves ctrl+f to the log search and lets a second ctrl+f reach the browser', function () {
    $page = visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertSee('Dashboard')
        ->navigate("/server/{$this->stack['server']->uuid}/sentinel/logs");

    $page->assertSee('log line 00001')
        ->assertSee('Ctrl+F')
        ->script('document.activeElement.blur()');

    $page->keys('body', 'Control+f');

    expect($page->script('document.activeElement.getAttribute("aria-label")'))->toBe('Find in logs');

    // In the search field, Ctrl+F is not intercepted, so the browser find can open.
    expect($page->script('(() => {
        const event = new KeyboardEvent("keydown", { key: "f", ctrlKey: true, bubbles: true, cancelable: true });
        document.activeElement.dispatchEvent(event);
        return event.defaultPrevented;
    })()'))->toBeFalse();

    $page->screenshot(filename: 'runtime-logs-find-shortcut');
});

it('streams only new lines and appends them to the loaded log', function () {
    $page = visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertSee('Dashboard')
        ->navigate("/server/{$this->stack['server']->uuid}/sentinel/logs");

    $page->assertSee('log line 00001')
        ->click('[title="Stream Logs"]')
        ->wait(3)
        ->click('[title="Follow Logs"]')
        ->wait(0.5)
        ->assertSee('streamed line after since')
        ->assertSee('log line 50000');

    Process::assertRan(fn ($process) => str_contains($process->command, 'docker logs --since 2026-09-28T10:13:53.000050000Z -t coolify-sentinel'));

    $page->screenshot(filename: 'runtime-logs-virtualized-stream');
});

it('falls back to the default level filters when the saved filters are invalid', function () {
    $page = visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertSee('Dashboard');

    $page->script('localStorage.setItem("coolify-log-filters", "{")');
    $page->navigate("/server/{$this->stack['server']->uuid}/sentinel/logs")
        ->assertSee('log line 00001');

    // A valid saved preference is still applied.
    $page->script('localStorage.setItem("coolify-log-filters", JSON.stringify({ info: false }))');
    $page->navigate("/server/{$this->stack['server']->uuid}/sentinel/logs")
        ->wait(1)
        ->assertDontSee('log line 00001');

    $page->screenshot(filename: 'runtime-logs-invalid-saved-filters');
});

it('opens runtime logs in text view and remembers the choice', function () {
    $page = visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertSee('Dashboard');

    $page->script('localStorage.removeItem("coolify-log-text-view")');
    $page->navigate("/server/{$this->stack['server']->uuid}/sentinel/logs")
        ->assertSee('log line 00001');

    expect($page->script('document.getElementById("logs").classList.contains("runtime-log-text-view")'))->toBeTrue()
        ->and($page->script('document.querySelector("[data-log-line]").getBoundingClientRect().height'))->toBeLessThan(24);

    // Rows rendered for the default view sit against each other.
    expect($page->script('(() => {
        const rows = [...document.querySelectorAll(".runtime-log-virtual-row")].sort((a, b) => a.dataset.index - b.dataset.index);
        return rows.slice(1).every((row, i) => row.getBoundingClientRect().top - rows[i].getBoundingClientRect().bottom < 1);
    })()'))->toBeTrue();

    $page->click('log line 00001')
        ->wait(0.3);

    expect($page->script('document.querySelectorAll(".runtime-log-detail").length'))->toBe(0);

    // Turning text view off leaves the grid.
    $page->click('[title="View Options"]')
        ->click('button:visible:has-text("Text view")')
        ->wait(0.3);

    $page->screenshot(filename: 'runtime-logs-view-menu');

    expect($page->script('document.getElementById("logs").classList.contains("runtime-log-text-view")'))->toBeFalse()
        ->and($page->script('document.querySelector("[data-log-line]").getBoundingClientRect().height'))->toBeGreaterThan(24);

    // A short line shows everything in the row, so it has nothing to expand.
    $page->click('log line 00001')
        ->wait(0.3);

    expect($page->script('document.querySelectorAll(".runtime-log-detail").length'))->toBe(0)
        ->and($page->script('document.querySelectorAll("[data-log-line][data-expandable]").length'))->toBe(0);

    $page->click('[title="View Options"]')
        ->click('button:visible:has-text("Text view")')
        ->wait(0.3);

    $page->screenshot(filename: 'runtime-logs-text-view');

    $page->navigate("/server/{$this->stack['server']->uuid}/sentinel/logs")
        ->assertSee('log line 00001');

    expect($page->script('document.getElementById("logs").classList.contains("runtime-log-text-view")'))->toBeTrue();
});

it('keeps log text left aligned and the first line against the top of the viewport', function () {
    $application = createBrowserApplication($this->stack, ['uuid' => 'app-runtime-logs']);
    $lines = implode("\n", array_map(
        fn (int $i) => sprintf('2026-09-28T10:00:%02dZ layout line %05d', $i, $i),
        range(1, 40),
    ));
    Process::fake(function (PendingProcess $process) use ($lines) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        if (str_contains($command, 'docker logs')) {
            return Process::result(output: $lines);
        }
        if (str_contains($command, 'docker ps')) {
            return Process::result(output: json_encode([
                'ID' => 'appcontainer',
                'Names' => 'appcontainer',
                'State' => 'running',
                'Labels' => 'coolify.pullRequestId=0,com.docker.compose.service=web',
            ])."\n");
        }

        return Process::result();
    });

    $url = applicationConfigurationUrl($this->stack['project'], $this->stack['environment'], $application).'/logs';
    $page = visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertSee('Dashboard');

    $page->script('localStorage.setItem("pageWidth", "full"); localStorage.removeItem("coolify-log-text-view");');
    $page->resize(1800, 960)
        ->navigate($url)
        ->wait(1)
        ->assertSee('layout line 00001');

    expect($page->script(<<<'JS'
        (() => {
            const line = document.querySelector('[data-log-line]');
            const viewport = document.getElementById('logsContainer');
            const style = getComputedStyle(line);
            const topGap = Math.abs(line.getBoundingClientRect().top - viewport.getBoundingClientRect().top);
            const main = document.querySelector('main');
            const workspace = document.querySelector('.runtime-logs-workspace');
            const column = document.querySelector('.runtime-logs-column');
            const padding = parseFloat(getComputedStyle(main).paddingBottom);
            const bottomGap = main.getBoundingClientRect().bottom - workspace.getBoundingClientRect().bottom;
            const widthGap = Math.abs(workspace.getBoundingClientRect().width - column.getBoundingClientRect().width);
            return viewport.scrollTop === 0 && topGap < 2 && Math.abs(bottomGap - padding) < 2 && widthGap < 2
                && style.textAlign !== 'center' && style.alignItems !== 'center';
        })()
    JS))->toBeTrue();

    $fullWidth = $page->script('document.querySelector(".runtime-logs-workspace").getBoundingClientRect().width');

    $page->script('localStorage.setItem("pageWidth", "centered")');
    $page->navigate($url)
        ->assertSee('layout line 00001');

    expect($page->script('document.querySelector(".runtime-logs-workspace").getBoundingClientRect().width'))
        ->toBeLessThan($fullWidth);

    $page->resize(390, 844)
        ->assertSee('layout line 00001');

    expect($page->script(<<<'JS'
        (() => {
            const line = document.querySelector('[data-log-line]');
            const style = getComputedStyle(line);
            const viewport = document.getElementById('logsContainer');
            const topGap = Math.abs(line.getBoundingClientRect().top - viewport.getBoundingClientRect().top);
            return viewport.scrollTop === 0 && topGap < 2
                && style.flexDirection === 'column'
                && style.alignItems === 'flex-start'
                && (style.textAlign === 'left' || style.textAlign === 'start');
        })()
    JS))->toBeTrue();
});
