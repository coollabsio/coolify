@props([
    'title',
    'subtitle' => null,
    'href' => null,
    'actionLabel' => 'View all',
    'icon' => 'arrow-right',
])

{{--
    Shared section heading: a 14px title, an optional 11px muted subtitle, and an
    optional right-aligned action. Pass `href` for the default ghost "View all"
    button, or an `<x-slot:actions>` for a custom control.
--}}
<div {{ $attributes->merge(['class' => 'mb-3 min-w-0']) }}>
    <div class="flex items-center justify-between gap-4">
        <h2 class="min-w-0 truncate text-[14px]! leading-5! font-semibold! text-black dark:text-fg">
            {{ $title }}
        </h2>
        @isset($actions)
            <div class="shrink-0">{{ $actions }}</div>
        @elseif (filled($href))
            <a href="{{ $href }}" {{ wireNavigate() }}
                class="group inline-flex h-7 shrink-0 items-center gap-1.5 rounded-md border border-neutral-200 bg-white px-2.5 text-[12px] font-medium text-neutral-600 transition-[color,background-color,transform] duration-100 ease-out hover:bg-neutral-100 hover:text-black active:scale-[0.97] dark:border-white/[0.08] dark:bg-white/[0.06] dark:text-fg-dim dark:hover:bg-white/[0.1] dark:hover:text-fg">
                {{ $actionLabel }}
                @if ($icon)
                    <x-reicon :name="$icon" class="size-3 opacity-70 transition-transform duration-150 ease-out group-hover:translate-x-0.5" />
                @endif
            </a>
        @endisset
    </div>
    @if (filled($subtitle))
        <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint">{{ $subtitle }}</p>
    @endif
</div>
