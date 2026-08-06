@php
    $applicationStatus = str($application->status ?? 'exited');
    [$statusDotClass, $statusLabel] = match (true) {
        $applicationStatus->startsWith('running') => ['bg-[#3fb950]', 'Running'],
        $applicationStatus->startsWith('degraded') => ['bg-orange-400', 'Degraded'],
        $applicationStatus->startsWith('restarting'),
        $applicationStatus->startsWith('starting') => ['bg-warning', 'Restarting'],
        default => ['bg-neutral-400 dark:bg-fg-faint', 'Stopped'],
    };
@endphp

<span wire:poll.10000ms="refreshStatus"
    class="inline-flex h-[22px] shrink-0 items-center gap-1.5 rounded-full border border-neutral-200 bg-neutral-100 px-2.5 text-xs font-medium text-black dark:border-white/[0.12] dark:bg-white/[0.08] dark:text-fg"
    title="{{ $application->status }}">
    <span class="size-1.5 rounded-full {{ $statusDotClass }}"></span>
    {{ $statusLabel }}
</span>
