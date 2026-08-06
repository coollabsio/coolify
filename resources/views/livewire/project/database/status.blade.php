@php
    $databaseStatus = str($database->status ?? 'exited');
    [$statusDotClass, $statusLabel] = match (true) {
        $databaseStatus->startsWith('running') => ['bg-[#3fb950]', 'Running'],
        $databaseStatus->startsWith('degraded') => ['bg-orange-400', 'Degraded'],
        $databaseStatus->startsWith('restarting'),
        $databaseStatus->startsWith('starting') => ['bg-warning', 'Restarting'],
        default => ['bg-neutral-400 dark:bg-fg-faint', 'Stopped'],
    };
@endphp

<span wire:poll.10000ms="refreshStatus"
    class="inline-flex h-[22px] shrink-0 items-center gap-1.5 rounded-full border border-neutral-200 bg-neutral-100 px-2.5 text-xs font-medium text-black dark:border-white/[0.12] dark:bg-white/[0.08] dark:text-fg"
    title="{{ $database->status }}">
    <span class="size-1.5 rounded-full {{ $statusDotClass }}"></span>
    {{ $statusLabel }}
</span>
