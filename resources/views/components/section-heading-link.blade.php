{{-- The "View all" link of x-section-heading (uses its $href, $actionLabel, and $icon). --}}
<a href="{{ $href }}" {{ wireNavigate() }}
    class="group inline-flex shrink-0 items-center gap-0.5 text-[11px] text-neutral-500 hover:text-black hover:underline dark:text-fg-faint dark:hover:text-fg">
    {{ $actionLabel }}
    @if ($icon)
        <x-reicon :name="$icon" class="size-3 transition-transform duration-150 ease-out group-hover:translate-x-0.5" />
    @endif
</a>
