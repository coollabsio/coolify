@props(['status', 'href' => null, 'size' => 'md'])
@php
    $type = match ($status['tone'] ?? 'neutral') {
        'success' => 'success',
        'warning' => 'warning',
        'error' => 'error',
        default => 'neutral',
    };
    $pulse = ($status['tone'] ?? null) === 'progress';
    $navigate = wireNavigate();
@endphp
@if ($href)
    {{-- Ghost hover drawn outside the box, as on clickable status badges. --}}
    <a href="{{ $href }}" @if ($navigate) wire:navigate @endif title="coolify.json sync"
        {{ $attributes->class('relative isolate inline-flex shrink-0 before:absolute before:-inset-x-1.5 before:-inset-y-1 before:-z-10 before:rounded-md before:transition-colors hover:before:bg-neutral-100 dark:hover:before:bg-white/[0.07]') }}>
        <x-reicon name="file-content" class="mr-1.5 size-3.5 self-center text-neutral-400 dark:text-fg-faint" />
        <x-status-badge :type="$type" :pulse="$pulse" :size="$size" :label="$status['label']" />
    </a>
@else
    <x-status-badge :type="$type" :pulse="$pulse" :size="$size" :label="$status['label']" {{ $attributes }} />
@endif
