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
        ->assertSee('Dashboard')
        ->navigate("/server/{$this->stack['server']->uuid}/sentinel/logs");

    $page->assertSee('log line 00001')
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
