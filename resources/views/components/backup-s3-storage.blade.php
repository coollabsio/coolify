@props([
    'enabled' => false,
    'names' => collect(),
    'tooltip' => null,
])

{{-- S3 is a setting, not a state: plain text keeps the status badge the only pill in the row. --}}
<span {{ $attributes->class('inline-flex max-w-full min-w-0 items-center gap-1.5') }}
    @if ($tooltip) data-tooltip="{{ $tooltip }}" aria-label="{{ $tooltip }}" tabindex="0" @endif>
    @if (! $enabled)
        <span class="text-neutral-400 dark:text-fg-faint">Local only</span>
    @elseif ($names->isEmpty())
        <x-reicon name="alert-triangle" class="size-3.5 shrink-0 text-red-500" />
        <span class="text-red-600 dark:text-red-400">Unavailable</span>
    @else
        <span class="truncate">{{ $names->first() }}</span>
        @if ($names->count() > 1)
            <span class="shrink-0 text-neutral-400 dark:text-fg-faint">+{{ $names->count() - 1 }}</span>
        @endif
    @endif
</span>
