<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    $this->stack = seedBrowserResourceStack();
});

it('keeps service options left aligned beside their status dot', function () {
    $application = createBrowserApplication($this->stack, ['uuid' => 'app-runtime-menu']);
    $containers = implode("\n", [
        json_encode(['ID' => 'api', 'Names' => 'api', 'State' => 'exited', 'Labels' => 'coolify.pullRequestId=0,com.docker.compose.service=api']),
        json_encode(['ID' => 'web-new', 'Names' => 'web-new', 'State' => 'running', 'Labels' => 'coolify.pullRequestId=0,com.docker.compose.service=web']),
        json_encode(['ID' => 'web-old', 'Names' => 'web-old', 'State' => 'exited', 'Labels' => 'coolify.pullRequestId=0,com.docker.compose.service=web']),
        json_encode(['ID' => 'worker', 'Names' => 'worker', 'State' => 'running', 'Labels' => 'coolify.pullRequestId=0,com.docker.compose.service=worker']),
    ]);
    Process::fake(function (PendingProcess $process) use ($containers) {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
        if (str_contains($command, 'docker logs')) {
            return Process::result(output: "2026-09-28T10:00:01Z web log line 01\n");
        }
        if (str_contains($command, 'docker ps')) {
            return Process::result(output: $containers."\n");
        }

        return Process::result();
    });

    $url = applicationConfigurationUrl($this->stack['project'], $this->stack['environment'], $application).'/logs';
    $page = visit('/login')
        ->fill('email', 'test@example.com')
        ->fill('password', 'password')
        ->click('Login')
        ->assertSee('Dashboard');

    $page->resize(1440, 960)
        ->navigate($url)
        ->assertSee('web log line 01');

    $page->script("document.querySelector('[aria-label=\"Dismiss notifications reminder\"]')?.click()");
    $page->click('#service-trigger')
        ->assertSee('Stopped')
        ->assertSee('Degraded')
        ->assertSee('2 containers')
        ->assertSee('worker');

    expect($page->script(<<<'JS'
        (() => {
            const options = [...document.querySelectorAll('#service-panel .listbox-option')];
            if (options.length < 3) return false;
            const stateText = (option) => [...option.querySelectorAll('span')]
                .map((element) => element.textContent.trim())
                .find((text) => ['Stopped', 'Degraded', 'Starting', 'Restarting'].includes(text)) ?? '';
            return options.every((option) => {
                const label = option.querySelector('span.truncate');
                if (!label) return false;
                const optionLeft = option.getBoundingClientRect().left;
                const labelLeft = label.getBoundingClientRect().left;
                const optionRight = option.getBoundingClientRect().right;
                const name = label.textContent.trim();
                const state = stateText(option);
                const expected = name === 'api' ? 'Stopped' : (name === 'web' ? 'Degraded' : '');
                return labelLeft - optionLeft < 40 && labelLeft < (optionLeft + optionRight) / 2 && state === expected;
            });
        })()
    JS))->toBeTrue();

    $page->screenshot(fullPage: false, filename: 'runtime_logs_service_menu');
});
