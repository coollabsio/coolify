<?php

it('keeps sentinel restarted events from re-syncing editable form fields', function () {
    $componentSource = file_get_contents(app_path('Livewire/Server/Sentinel.php'));

    preg_match('/public function handleSentinelRestarted\([^)]*\)\s*\{(?<body>.*?)\n    \}/s', $componentSource, $matches);

    expect($matches['body'] ?? '')
        ->toContain('$this->sentinelUpdatedAt = $this->server->sentinel_updated_at;')
        ->not->toContain('$this->syncData();');
});

it('refreshes display state after sentinel synchronization without changing editable fields', function () {
    $componentSource = file_get_contents(app_path('Livewire/Server/Sentinel.php'));

    expect($componentSource)
        ->toContain('SentinelSynchronized" => \'handleSentinelSynchronized\'');

    preg_match('/public function handleSentinelSynchronized\([^)]*\)(?:: [^{]+)?\s*\{(?<body>.*?)\n    \}/s', $componentSource, $matches);

    expect($matches['body'] ?? '')
        ->toContain('$this->sentinelUpdatedAt = $this->server->sentinel_updated_at;')
        ->not->toContain('$this->syncData();');
});

it('does not expose a Sentinel disable action', function () {
    $componentSource = file_get_contents(app_path('Livewire/Server/Sentinel.php'));
    $view = file_get_contents(resource_path('views/livewire/server/sentinel.blade.php'));

    expect($componentSource)->not->toContain('function toggleSentinel')
        ->and($view)->not->toContain('Disable')
        ->and($view)->not->toContain('Enable Sentinel');
});

it('does not repeat a disabled status badge in the sentinel empty state', function () {
    $view = file_get_contents(resource_path('views/livewire/server/sentinel.blade.php'));

    expect($view)->not->toContain("? 'Disabled'");
});

it('shows the connected sentinel state without a decorative icon or redundant heading', function () {
    $view = file_get_contents(resource_path('views/livewire/server/sentinel.blade.php'));

    expect($view)
        ->toContain('Sentinel is connected and reporting server health to this Coolify instance.')
        ->not->toContain('Health reporting active')
        ->not->toContain('<x-reicon name="dashboard"');
});

it('tells the user that saving sentinel settings initiates a restart', function () {
    $componentSource = file_get_contents(app_path('Livewire/Server/Sentinel.php'));

    preg_match('/public function submit\([^)]*\).*?\{(?<body>.*?)\n    \}/s', $componentSource, $matches);

    expect($matches['body'] ?? '')
        ->toContain("\$this->dispatch('success', 'Sentinel settings updated. Restarting Sentinel.');");
});

it('starts mandatory Sentinel after server validation succeeds', function () {
    $interactiveValidation = file_get_contents(app_path('Livewire/Server/ValidateAndInstall.php'));
    $queuedValidation = file_get_contents(app_path('Jobs/ValidateAndInstallServerJob.php'));

    expect($interactiveValidation)->toContain('CheckAndStartSentinelJob::dispatch($this->server);')
        ->and($queuedValidation)->toContain('CheckAndStartSentinelJob::dispatch($this->server);');
});
