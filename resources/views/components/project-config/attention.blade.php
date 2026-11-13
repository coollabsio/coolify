@props(['status', 'href'])
@php
    $isError = ($status['state'] ?? null) === 'failed';
@endphp
<div {{ $attributes->class([
    'flex items-center gap-3 rounded-lg border px-3 py-2 text-[13px]',
    'border-warning/25 bg-warning/[0.07] text-warning-900 dark:border-warning/20 dark:bg-warning/[0.06] dark:text-warning' => ! $isError,
    'border-red-300/60 bg-red-50 text-red-800 dark:border-red-500/20 dark:bg-red-500/[0.07] dark:text-red-300' => $isError,
]) }} role="status">
    <x-reicon :name="$isError ? 'alert-circle' : 'alert-triangle'" class="size-4 shrink-0" />
    <p class="min-w-0 flex-1 truncate" title="{{ $status['message'] }}">{{ $status['message'] }}</p>
    <a href="{{ $href }}" @if (wireNavigate()) wire:navigate @endif
        class="inline-flex shrink-0 items-center gap-1 font-semibold underline-offset-2 hover:underline">
        {{ $isError ? 'Open' : 'Review changes' }}
        <x-reicon name="arrow-right" class="size-3" />
    </a>
</div>
