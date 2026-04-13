<?php

it('has healthcheck properties on GetLogs component', function () {
    $getLogsFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Shared/GetLogs.php');

    expect($getLogsFile)
        ->toContain('public bool $showHealthcheckLogs = false')
        ->toContain('public string $healthcheckOutputs = ')
        ->toContain('public ?string $healthcheckStatus = null');
});

it('has getHealthcheckLogs method on GetLogs component', function () {
    $getLogsFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Shared/GetLogs.php');

    expect($getLogsFile)
        ->toContain('public function getHealthcheckLogs(): void');
});

it('has toggleHealthcheckLogs method on GetLogs component', function () {
    $getLogsFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Shared/GetLogs.php');

    expect($getLogsFile)
        ->toContain('public function toggleHealthcheckLogs(): void')
        ->toContain('$this->showHealthcheckLogs = ! $this->showHealthcheckLogs');
});

it('validates server ownership in getHealthcheckLogs', function () {
    $getLogsFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Shared/GetLogs.php');

    $methodStart = strpos($getLogsFile, 'public function getHealthcheckLogs(): void');
    $methodBody = substr($getLogsFile, $methodStart, 2000);

    expect($methodBody)
        ->toContain('Server::ownedByCurrentTeam()')
        ->toContain('Unauthorized');
});

it('validates container name in getHealthcheckLogs', function () {
    $getLogsFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Shared/GetLogs.php');

    $methodStart = strpos($getLogsFile, 'public function getHealthcheckLogs(): void');
    $methodBody = substr($getLogsFile, $methodStart, 2000);

    expect($methodBody)
        ->toContain('ValidationPatterns::isValidContainerName');
});

it('uses docker inspect to fetch health data', function () {
    $getLogsFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Shared/GetLogs.php');

    $methodStart = strpos($getLogsFile, 'public function getHealthcheckLogs(): void');
    $methodBody = substr($getLogsFile, $methodStart, 3000);

    expect($methodBody)
        ->toContain("docker inspect --format='{{json .State.Health}}'")
        ->toContain('SshMultiplexingHelper::generateSshCommand');
});

it('handles containers without healthcheck configured', function () {
    $getLogsFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Shared/GetLogs.php');

    $methodStart = strpos($getLogsFile, 'public function getHealthcheckLogs(): void');
    $methodBody = substr($getLogsFile, $methodStart, 3000);

    expect($methodBody)
        ->toContain('No healthcheck configured for this container.');
});

it('handles non-root servers with sudo in getHealthcheckLogs', function () {
    $getLogsFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Shared/GetLogs.php');

    $methodStart = strpos($getLogsFile, 'public function getHealthcheckLogs(): void');
    $methodBody = substr($getLogsFile, $methodStart, 2000);

    expect($methodBody)
        ->toContain('$this->server->isNonRoot()')
        ->toContain('parseCommandsByLineForSudo');
});

it('formats healthcheck log entries with PASS and FAIL indicators', function () {
    $getLogsFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Shared/GetLogs.php');

    $methodStart = strpos($getLogsFile, 'public function getHealthcheckLogs(): void');
    $methodBody = substr($getLogsFile, $methodStart, 5000);

    expect($methodBody)
        ->toContain("'PASS'")
        ->toContain("'FAIL'")
        ->toContain('ExitCode')
        ->toContain('Output');
});

it('parses health status and failing streak from docker inspect', function () {
    $getLogsFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Shared/GetLogs.php');

    $methodStart = strpos($getLogsFile, 'public function getHealthcheckLogs(): void');
    $methodBody = substr($getLogsFile, $methodStart, 5000);

    expect($methodBody)
        ->toContain("data_get(\$health, 'Status'")
        ->toContain("data_get(\$health, 'FailingStreak'")
        ->toContain("data_get(\$health, 'Log'");
});

it('has healthcheck toggle button in blade template', function () {
    $bladeFile = file_get_contents(__DIR__.'/../../resources/views/livewire/project/shared/get-logs.blade.php');

    expect($bladeFile)
        ->toContain('wire:click="toggleHealthcheckLogs"')
        ->toContain('Show Healthcheck Logs')
        ->toContain('Show Container Logs');
});

it('conditionally shows healthcheck display area in blade template', function () {
    $bladeFile = file_get_contents(__DIR__.'/../../resources/views/livewire/project/shared/get-logs.blade.php');

    expect($bladeFile)
        ->toContain('$showHealthcheckLogs')
        ->toContain('$healthcheckStatus')
        ->toContain('$healthcheckOutputs');
});

it('shows health status badge with correct color classes', function () {
    $bladeFile = file_get_contents(__DIR__.'/../../resources/views/livewire/project/shared/get-logs.blade.php');

    expect($bladeFile)
        ->toContain('Health Status:')
        ->toContain('bg-green-100')
        ->toContain('bg-red-100')
        ->toContain('bg-yellow-100');
});

it('uses slower poll interval for healthcheck mode', function () {
    $bladeFile = file_get_contents(__DIR__.'/../../resources/views/livewire/project/shared/get-logs.blade.php');

    expect($bladeFile)
        ->toContain("wire:poll.5000ms='getHealthcheckLogs'");
});

it('color-codes PASS and FAIL lines in healthcheck display', function () {
    $bladeFile = file_get_contents(__DIR__.'/../../resources/views/livewire/project/shared/get-logs.blade.php');

    expect($bladeFile)
        ->toContain("str_contains(\$line, 'FAIL')")
        ->toContain("str_contains(\$line, 'PASS')")
        ->toContain('text-red-500')
        ->toContain('text-green-600');
});
