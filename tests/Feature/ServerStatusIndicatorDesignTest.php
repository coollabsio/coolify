<?php

test('server cards use warning icons instead of colored icon borders', function () {
    $dashboard = file_get_contents(resource_path('views/livewire/dashboard.blade.php'));
    $serverIndex = file_get_contents(resource_path('views/livewire/server/index.blade.php'));

    expect($dashboard)
        ->not->toContain('<x-status-badge :status="$serverStatus"')
        ->not->toContain("'border-amber-500/70' => \$serverStatusType === 'warning'")
        ->toContain('@if ($serverStatusType !== \'success\')')
        ->toContain('data-tooltip="{{ $serverStatus }}"')
        ->toContain('<x-reicon name="alert-triangle"');

    expect(substr_count($serverIndex, '<x-status-badge'))->toBe(1)
        ->and($serverIndex)
        ->toContain("&& (\$server->proxy->status !== 'running' || \$server->hasCurrentTraefikOutdatedInfo())")
        ->toContain("\$sentinelNeedsAttention = \$isReady && \$server->isSentinelEnabled() && \$server->sentinelStatus() === 'out_of_sync'")
        ->toContain("\$proxyNeedsAttention || \$sentinelNeedsAttention => 'warning'")
        ->toContain("\$isReady => 'success'")
        ->toContain("\$isTransferredAway || \$server->settings->force_disabled => 'error'")
        ->toContain("default => 'error'")
        ->not->toContain("server.statusType === 'warning' ? 'border-amber-500/70'")
        ->toContain('x-show="server.statusType !== \'success\'"')
        ->toContain(':data-tooltip="server.status"')
        ->toContain(':aria-label="`Server status: ${server.status}`"');
});

test('dashboard server cards warn when proxy or sentinel needs attention', function () {
    $dashboard = file_get_contents(resource_path('views/livewire/dashboard.blade.php'));

    expect($dashboard)
        ->toContain("\$proxyNeedsAttention = \$server->proxySet() && (\$server->proxy->status !== 'running' || \$server->hasCurrentTraefikOutdatedInfo())")
        ->toContain("\$sentinelNeedsAttention = \$server->isSentinelEnabled() && \$server->sentinelStatus() === 'out_of_sync'")
        ->toContain("\$proxyNeedsAttention || \$sentinelNeedsAttention => ['Attention required', 'warning']");
});

test('server status summary uses warning indicators for proxy updates and sentinel outages', function () {
    $summary = file_get_contents(resource_path('views/components/server/status-summary.blade.php'));

    expect($summary)
        ->toContain('$server->hasCurrentTraefikOutdatedInfo()')
        ->toContain('$sentinelStatus = $server->sentinelStatus()')
        ->toContain("'waiting' => 'Waiting for first report'")
        ->toContain('$proxyStatusLabel = match (true)')
        ->toContain("\$proxyConfigurationPending => 'Restart required'")
        ->toContain("\$traefikUpdateAvailable => 'Update available'")
        ->toContain('{{ $proxyStatusLabel }}')
        ->toContain("'bg-warning' => \$proxyNeedsAttention && (\$proxyUpdateAvailable")
        ->toContain("'bg-warning' => \$sentinelStatus === 'out_of_sync'")
        ->not->toContain("\$server->isSentinelLive() ? 'bg-success' : 'bg-error'");
});

test('Sentinel logs show the pending first report state without an out-of-sync warning', function () {
    $logs = file_get_contents(resource_path('views/livewire/server/sentinel/logs.blade.php'));

    expect($logs)
        ->toContain("'waiting' => ['Waiting for first report', 'neutral']")
        ->toContain("'in_sync' => ['In sync', 'success']")
        ->toContain("default => ['Out of sync', 'warning']")
        ->not->toContain("\$server->isSentinelLive() ? 'In sync' : 'Out of sync'");
});

test('server table keeps status text without a badge', function () {
    $serverIndex = file_get_contents(resource_path('views/livewire/server/index.blade.php'));

    expect($serverIndex)
        ->toContain('<span x-text="server.status"></span>')
        ->toContain('text-[11px] font-medium')
        ->not->toContain('<x-status-badge dynamic>');
});
