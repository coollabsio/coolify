@props(['href'])

{{-- A small back button above a page title, for example "Servers" on the Add server pages. --}}
<a href="{{ $href }}" {{ wireNavigate() }}
    {{ $attributes->class('group mb-3 inline-flex h-7 items-center gap-1.5 rounded-md border border-neutral-200 bg-white pr-2.5 pl-1.5 text-[12px] font-medium text-neutral-600 shadow-sm transition-colors hover:border-neutral-300 hover:bg-neutral-50 hover:text-black hover:no-underline dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim dark:hover:border-white/[0.14] dark:hover:bg-white/[0.07] dark:hover:text-fg') }}>
    <x-reicon name="arrow-right" class="size-3.5 rotate-180 transition-transform group-hover:-translate-x-0.5" />
    {{ $slot }}
</a>
