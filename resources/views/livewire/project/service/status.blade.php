@php
    $serviceStatus = str($service->status ?? 'exited');
    [$statusDotClass, $statusLabel] = match (true) {
        $serviceStatus->startsWith('running') => ['bg-[#3fb950]', 'Running'],
        $serviceStatus->startsWith('degraded') => ['bg-orange-400', 'Degraded'],
        $serviceStatus->startsWith('restarting'),
        $serviceStatus->startsWith('starting') => ['bg-warning', 'Restarting'],
        default => ['bg-neutral-400 dark:bg-fg-faint', 'Stopped'],
    };
@endphp

<span wire:poll.10000ms="refreshStatus"
    class="inline-flex h-[22px] shrink-0 items-center gap-1.5 rounded-full border border-neutral-200 bg-neutral-100 px-2.5 text-xs font-medium text-black dark:border-white/[0.12] dark:bg-white/[0.08] dark:text-fg"
    title="{{ $service->status }}">
    <span class="size-1.5 rounded-full {{ $statusDotClass }}"></span>
    {{ $statusLabel }}
</span>
