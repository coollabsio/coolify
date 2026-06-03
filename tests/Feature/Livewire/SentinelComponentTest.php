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
