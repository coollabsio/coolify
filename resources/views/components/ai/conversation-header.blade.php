@props([
    'title' => 'Assistant',
    'subtitle' => null,
    'online' => false,
    'avatarSize' => 'sm',
])

{{-- Shared conversation top bar for both the /assistant page and the floating
     widget, built to the layer-card header spec (DESIGN.md §5 / §6): a compact
     3rem elevated strip with a subtle title. It carries no background or border
     of its own; the card shell supplies the elevated surface and the base-bg
     body below provides the separation. Left: identity + title (+ optional
     badge/subtitle). Right: an `actions` slot the caller fills. --}}
<header
    {{ $attributes->class('flex min-h-12 shrink-0 items-center justify-between gap-2 py-2 pr-2 pl-4') }}>
    <div class="flex min-w-0 items-center gap-2.5">
        <x-ai.avatar :size="$avatarSize" :online="$online" />
        <div class="flex min-w-0 flex-col leading-tight">
            <div class="flex min-w-0 items-center gap-1.5">
                <span class="truncate text-sm font-medium text-neutral-700 dark:text-fg-dim">{{ $title }}</span>
                {{ $badge ?? '' }}
            </div>
            @if (filled($subtitle))
                <span class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint">{{ $subtitle }}</span>
            @endif
        </div>
    </div>
    @isset($actions)
        <div class="flex shrink-0 items-center gap-0.5">{{ $actions }}</div>
    @endisset
</header>
