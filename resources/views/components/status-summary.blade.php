@props(['status', 'title' => 'Application status', 'containerName' => 'Container'])

@php
    $rawStatus = str((string) $status)->lower()->trim()->value();
    $statusParts = explode(':', $rawStatus);
    $containerStatus = $statusParts[0] ?? 'unknown';
    $healthStatus = $statusParts[1] ?? null;
    $monitoringExcluded = in_array('excluded', $statusParts, true);

    if ($healthStatus === null && preg_match('/^([^()]+)\(([^)]+)\)$/', $rawStatus, $matches)) {
        $containerStatus = trim($matches[1]);
        $healthStatus = trim($matches[2]);
    }

    $containerLabel = str($containerStatus ?: 'unknown')->headline()->value();
    $healthLabel = match (true) {
        $monitoringExcluded => 'Monitoring disabled',
        $healthStatus === 'healthy' => 'Healthy',
        $healthStatus === 'unhealthy' => 'Unhealthy',
        $healthStatus === 'starting' => 'Starting',
        default => 'Not configured',
    };

    $containerType = match (true) {
        str($containerStatus)->startsWith('running') => 'success',
        str($containerStatus)->startsWith(['starting', 'restarting', 'degraded']) => 'warning',
        default => 'error',
    };

    $healthType = match (true) {
        $monitoringExcluded => 'warning',
        $healthStatus === 'healthy' => 'success',
        $healthStatus === 'unhealthy' => 'error',
        default => 'warning',
    };

    [$summaryLabel, $summaryType] = match (true) {
        $containerType === 'error' => [$containerLabel, 'error'],
        str($containerStatus)->startsWith('degraded') => ['Degraded', 'warning'],
        $healthType === 'error' => ['Degraded', 'error'],
        $containerType === 'warning' => [$containerLabel, 'warning'],
        $monitoringExcluded => ["{$containerLabel} (monitoring disabled)", 'warning'],
        $healthStatus === 'starting' => ["{$containerLabel} (healthcheck starting)", 'warning'],
        $healthType === 'warning' => ["{$containerLabel} (no healthcheck)", 'warning'],
        default => [$containerLabel, 'success'],
    };
@endphp

<div class="relative shrink-0" x-data="{ open: false }" @click.outside="open = false"
    @keydown.escape.window="open = false">
    <x-status-badge as="button" dynamic @click="open = !open" x-bind:aria-expanded="open" aria-haspopup="menu"
        class="cursor-pointer">
        <span @class([
            'size-1.5 shrink-0 rounded-full',
            'bg-success' => $summaryType === 'success',
            'bg-warning' => $summaryType === 'warning',
            'bg-error' => $summaryType === 'error',
        ])></span>
        <span>{{ $summaryLabel }}</span>
        <span class="inline-flex transition-transform" :class="open && 'rotate-180'">
            <x-reicon name="chevron-down" class="size-3 opacity-55" />
        </span>
    </x-status-badge>

    <div x-cloak x-show="open" x-transition.origin.top.left
        class="listbox-panel top-8! right-auto! left-0! z-[90]! w-[min(16rem,calc(100vw-1.5rem))]! min-w-0! sm:w-64! sm:min-w-64!" role="menu">
        <div class="px-3 py-2 text-[11px] font-medium text-neutral-400 dark:text-fg-faint">{{ $title }}</div>
        <div class="listbox-option cursor-default! gap-2.5!">
            <span @class([
                'size-1.5 shrink-0 rounded-full',
                'bg-success' => $containerType === 'success',
                'bg-warning' => $containerType === 'warning',
                'bg-error' => $containerType === 'error',
            ])></span>
            <span class="flex-1">{{ $containerName }}</span>
            <span>{{ $containerLabel }}</span>
        </div>
        <div class="listbox-option cursor-default! gap-2.5!">
            <span @class([
                'size-1.5 shrink-0 rounded-full',
                'bg-success' => $healthType === 'success',
                'bg-warning' => $healthType === 'warning',
                'bg-error' => $healthType === 'error',
            ])></span>
            <span class="flex-1">Healthcheck</span>
            <span class="inline-flex items-center gap-1.5">
                {{ $healthLabel }}
                @if ($healthLabel === 'Not configured')
                    <x-helper label="About unconfigured healthchecks"
                        helper="No healthcheck is configured, so Coolify can only report the container state. Traffic can still be routed to the container, but Coolify cannot verify that the application inside it is ready to receive requests." />
                @endif
            </span>
        </div>
    </div>
</div>
