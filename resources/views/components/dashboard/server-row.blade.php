@props([
    'server',
])

@php
    $isConnected = $server->settings?->is_reachable && ! $server->settings?->force_disabled;
    $isDisabled = (bool) $server->settings?->force_disabled;
@endphp

<a href="{{ route('server.show', ['server_uuid' => $server->uuid]) }}" {{ wireNavigate() }}
    @class([
        'flex h-20 w-full items-center justify-between gap-2 p-2 border-l-2 bg-white dark:bg-coolgray-100 hover:opacity-90',
        'border-success' => $isConnected,
        'border-error' => ! $isConnected && ! $isDisabled,
        'border-neutral-400' => $isDisabled,
    ])>
    <div class="flex min-w-0 flex-col justify-center gap-0.5 text-sm">
        <span class="truncate font-medium dark:text-white">{{ $server->name }}</span>
        <span class="truncate text-xs text-neutral-500">{{ $server->description ?: "\u{00A0}" }}</span>
        <span class="truncate text-xs text-neutral-500">{{ $server->ip }}</span>
    </div>
    <span @class([
        'shrink-0 rounded-md px-3 py-1 text-xs font-medium shadow-xs',
        'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-200' => $isConnected,
        'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-200' => ! $isConnected && ! $isDisabled,
        'bg-gray-100 text-gray-700 dark:bg-gray-600/30 dark:text-gray-300' => $isDisabled,
    ])>
        @if ($isDisabled)
            Disabled
        @elseif ($isConnected)
            Connected
        @else
            Disconnected
        @endif
    </span>
</a>
