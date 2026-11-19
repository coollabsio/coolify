{{--
    Generic shimmer block. Size and shape are passed via `class`, e.g.
    <x-skeleton class="h-6 w-24 rounded-full" />. Composed by the x-skeleton.* helpers
    (tiles, table) and by component placeholder() views for lazy-loaded pages.
--}}
<div aria-hidden="true" {{ $attributes->class(['animate-pulse rounded-md bg-neutral-200/80 dark:bg-white/[0.06]']) }}></div>
