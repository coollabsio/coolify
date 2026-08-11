<?php

test('server cards use icon borders instead of ready badges', function () {
    $dashboard = file_get_contents(resource_path('views/livewire/dashboard.blade.php'));
    $serverIndex = file_get_contents(resource_path('views/livewire/server/index.blade.php'));

    expect($dashboard)
        ->not->toContain('<x-status-badge :status="$serverStatus"')
        ->toContain("'border-emerald-500/70' => \$serverStatusType === 'success'")
        ->toContain("'border-amber-500/70' => \$serverStatusType === 'warning'")
        ->toContain("'border-red-500/70' => \$serverStatusType === 'error'")
        ->toContain('title="{{ $serverStatus }}"')
        ->toContain('aria-label="Server status: {{ $serverStatus }}"');

    expect(substr_count($serverIndex, '<x-status-badge'))->toBe(1)
        ->and($serverIndex)
        ->toContain("\$proxyNeedsAttention = \$isReady && \$server->proxySet() && \$server->proxy->status !== 'running'")
        ->toContain('$sentinelNeedsAttention = $isReady && $server->isSentinelEnabled() && ! $server->isSentinelLive()')
        ->toContain("\$proxyNeedsAttention || \$sentinelNeedsAttention => 'warning'")
        ->toContain("\$isReady => 'success'")
        ->toContain("\$isTransferredAway || \$server->settings->force_disabled => 'error'")
        ->toContain("default => 'error'")
        ->toContain("server.statusType === 'success' ? 'border-emerald-500/70'")
        ->toContain("server.statusType === 'warning' ? 'border-amber-500/70'")
        ->toContain("'border-red-500/70'")
        ->toContain(':title="server.status"')
        ->toContain(':aria-label="`Server status: ${server.status}`"');
});

test('dashboard server cards warn when proxy or sentinel needs attention', function () {
    $dashboard = file_get_contents(resource_path('views/livewire/dashboard.blade.php'));

    expect($dashboard)
        ->toContain("\$proxyNeedsAttention = \$server->proxySet() && \$server->proxy->status !== 'running'")
        ->toContain('$sentinelNeedsAttention = $server->isSentinelEnabled() && ! $server->isSentinelLive()')
        ->toContain("\$proxyNeedsAttention || \$sentinelNeedsAttention => ['Attention required', 'warning']");
});

test('server table keeps status text without a badge', function () {
    $serverIndex = file_get_contents(resource_path('views/livewire/server/index.blade.php'));

    expect($serverIndex)
        ->toContain('<span x-text="server.status"></span>')
        ->toContain('text-[11px] font-medium')
        ->not->toContain('<x-status-badge dynamic>');
});
