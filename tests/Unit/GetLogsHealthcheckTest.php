<?php

beforeEach(function () {
    $this->getLogsFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Shared/GetLogs.php');

    $methodStart = strpos($this->getLogsFile, 'public function getHealthcheckLogs(): void');
    $this->methodBody = substr($this->getLogsFile, $methodStart, 5000);

    $this->bladeFile = file_get_contents(__DIR__.'/../../resources/views/livewire/project/shared/get-logs.blade.php');
});

it('has healthcheck properties on GetLogs component', function () {
    expect($this->getLogsFile)
        ->toContain('public bool $showHealthcheckLogs = false')
        ->toContain('public string $healthcheckOutputs = ')
        ->toContain('public ?string $healthcheckStatus = null');
});

it('has getHealthcheckLogs method on GetLogs component', function () {
    expect($this->getLogsFile)
        ->toContain('public function getHealthcheckLogs(): void');
});

it('has toggleHealthcheckLogs method on GetLogs component', function () {
    expect($this->getLogsFile)
        ->toContain('public function toggleHealthcheckLogs(): void')
        ->toContain('$this->showHealthcheckLogs = ! $this->showHealthcheckLogs');
});

it('validates server ownership in getHealthcheckLogs', function () {
    expect($this->methodBody)
        ->toContain('Server::ownedByCurrentTeam()')
        ->toContain('Unauthorized');
});

it('validates container name in getHealthcheckLogs', function () {
    expect($this->methodBody)
        ->toContain('ValidationPatterns::isValidContainerName');
});

it('uses docker inspect to fetch health data', function () {
    expect($this->methodBody)
        ->toContain("docker inspect --format='{{json .State.Health}}'")
        ->toContain('SshMultiplexingHelper::generateSshCommand');
});

it('handles containers without healthcheck configured', function () {
    expect($this->methodBody)
        ->toContain('No healthcheck configured for this container.');
});

it('handles non-root servers with sudo in getHealthcheckLogs', function () {
    expect($this->methodBody)
        ->toContain('$this->server->isNonRoot()')
        ->toContain('parseCommandsByLineForSudo');
});

it('formats healthcheck log entries with PASS and FAIL indicators', function () {
    expect($this->methodBody)
        ->toContain("'PASS'")
        ->toContain("'FAIL'")
        ->toContain('ExitCode')
        ->toContain('Output');
});

it('parses health status and failing streak from docker inspect', function () {
    expect($this->methodBody)
        ->toContain("data_get(\$health, 'Status'")
        ->toContain("data_get(\$health, 'FailingStreak'")
        ->toContain("data_get(\$health, 'Log'");
});

it('has healthcheck toggle button in blade template', function () {
    expect($this->bladeFile)
        ->toContain('wire:click="toggleHealthcheckLogs"')
        ->toContain('Show Healthcheck Logs')
        ->toContain('Show Container Logs');
});

it('conditionally shows healthcheck display area in blade template', function () {
    expect($this->bladeFile)
        ->toContain('$showHealthcheckLogs')
        ->toContain('$healthcheckStatus')
        ->toContain('$healthcheckOutputs');
});

it('shows health status badge with correct color classes', function () {
    expect($this->bladeFile)
        ->toContain('Health Status:')
        ->toContain('bg-green-100')
        ->toContain('bg-red-100')
        ->toContain('bg-yellow-100');
});

it('uses slower poll interval for healthcheck mode', function () {
    expect($this->bladeFile)
        ->toContain("wire:poll.5000ms='getHealthcheckLogs'");
});

it('color-codes PASS and FAIL lines in healthcheck display', function () {
    expect($this->bladeFile)
        ->toContain("str_contains(\$line, 'FAIL')")
        ->toContain("str_contains(\$line, 'PASS')")
        ->toContain('text-red-500')
        ->toContain('text-green-600');
});

it('refresh button dispatches correct method based on healthcheck mode', function () {
    expect($this->bladeFile)
        ->toContain("\$showHealthcheckLogs ? 'getHealthcheckLogs' : 'getLogs(true)'");
});

it('copyLogs returns healthcheck outputs when in healthcheck mode', function () {
    $copyMethodStart = strpos($this->getLogsFile, 'public function copyLogs(): string');
    $copyMethodBody = substr($this->getLogsFile, $copyMethodStart, 500);

    expect($copyMethodBody)
        ->toContain('$this->showHealthcheckLogs')
        ->toContain('$this->healthcheckOutputs');
});
