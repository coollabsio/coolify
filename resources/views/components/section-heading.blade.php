@props([
    'title',
    'subtitle' => null,
    'href' => null,
    'actionLabel' => 'View all',
    'icon' => null,
])

{{--
    Shared section heading: a 14px title, an optional 11px muted subtitle, and an
    optional action. Pass `href` for a small "View all" link and an
    `<x-slot:actions>` for a right-aligned control; with both, the link sits
    before the control, otherwise next to the title. `<x-slot:titleAction>`
    puts a small control (such as a + button) right after the title.
--}}
<div {{ $attributes->merge(['class' => 'mb-3 min-w-0']) }}>
    <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2">
        <div class="flex min-w-0 items-baseline gap-2">
            <div class="flex min-w-0 items-center gap-1">
                <h2 class="min-w-0 truncate text-[14px]! leading-5! font-semibold! text-black dark:text-fg">
                    {{ $title }}
                </h2>
                @isset($titleAction)
                    {{ $titleAction }}
                @endisset
            </div>
            @if (filled($href) && ! isset($actions))
                @include('components.section-heading-link')
            @endif
        </div>
        @isset($actions)
            <div class="ml-auto flex shrink-0 items-center gap-3">
                @if (filled($href))
                    @include('components.section-heading-link')
                @endif
                {{ $actions }}
            </div>
        @endisset
    </div>
    @if (filled($subtitle))
        <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint">{{ $subtitle }}</p>
    @endif
</div>
