<?php

it('keeps sentinel restarted events from re-syncing editable form fields', function () {
    $componentSource = file_get_contents(app_path('Livewire/Server/Sentinel.php'));

    preg_match('/public function handleSentinelRestarted\([^)]*\)\s*\{(?<body>.*?)\n    \}/s', $componentSource, $matches);

    expect($matches['body'] ?? '')
        ->toContain('$this->sentinelUpdatedAt = $this->server->sentinel_updated_at;')
        ->not->toContain('$this->syncData();');
});

it('dispatches a server navbar refresh after toggling sentinel', function () {
    $componentSource = file_get_contents(app_path('Livewire/Server/Sentinel.php'));

    preg_match('/public function toggleSentinel\([^)]*\).*?\{(?<body>.*?)
    \}/s', $componentSource, $matches);

    expect($matches['body'] ?? '')
        ->toContain("\$this->dispatch('refreshServerShow');");
});

it('only marks sentinel enabled after startup succeeds', function () {
    $componentSource = file_get_contents(app_path('Livewire/Server/Sentinel.php'));

    preg_match('/public function toggleSentinel\([^)]*\).*?\{(?<body>.*?)\n    \}/s', $componentSource, $matches);
    $toggleBody = $matches['body'] ?? '';

    expect(strpos($toggleBody, 'StartSentinel::run'))->toBeLessThan(
        strpos($toggleBody, '$this->isSentinelEnabled = true;')
    );
});

it('does not repeat a disabled status badge in the sentinel empty state', function () {
    $view = file_get_contents(resource_path('views/livewire/server/sentinel.blade.php'));

    expect($view)->not->toContain("? 'Disabled'");
});

it('tells the user that saving sentinel settings initiates a restart', function () {
    $componentSource = file_get_contents(app_path('Livewire/Server/Sentinel.php'));

    preg_match('/public function submit\([^)]*\).*?\{(?<body>.*?)\n    \}/s', $componentSource, $matches);

    expect($matches['body'] ?? '')
        ->toContain("\$this->dispatch('success', 'Sentinel settings updated. Restarting Sentinel.');");
});
