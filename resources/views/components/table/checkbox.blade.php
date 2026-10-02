@props(['label' => 'Select row'])

<span class="group relative flex size-[18px] shrink-0 cursor-pointer">
    <input type="checkbox" aria-label="{{ $label }}"
        {{ $attributes->class(['peer absolute inset-0 z-10 m-0 h-full w-full cursor-pointer appearance-none opacity-0']) }} />
    <span
        class="pointer-events-none absolute inset-0 rounded-[5px] border border-neutral-300 bg-white shadow-[inset_0_1px_1px_rgb(0_0_0/0.04)] transition-colors group-hover:border-neutral-400 peer-checked:border-coollabs peer-checked:bg-coollabs peer-indeterminate:border-coollabs peer-indeterminate:bg-coollabs peer-focus-visible:ring-2 peer-focus-visible:ring-coollabs/25 peer-focus-visible:ring-offset-2 dark:border-white/[0.14] dark:bg-white/[0.045] dark:shadow-none dark:group-hover:border-white/[0.22] dark:peer-checked:border-warning dark:peer-checked:bg-warning dark:peer-indeterminate:border-warning dark:peer-indeterminate:bg-warning dark:peer-focus-visible:ring-warning/30 dark:peer-focus-visible:ring-offset-base"></span>
    <svg class="pointer-events-none absolute inset-0 m-auto size-3 scale-75 text-white opacity-0 transition-[opacity,transform] peer-checked:scale-100 peer-checked:opacity-100 dark:text-black"
        viewBox="0 0 12 12" fill="none" aria-hidden="true">
        <path d="m2.25 6.15 2.35 2.3 5.15-5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
            stroke-linejoin="round" />
    </svg>
    <svg class="pointer-events-none absolute inset-0 m-auto size-3 text-white opacity-0 transition-opacity peer-indeterminate:opacity-100 dark:text-black"
        viewBox="0 0 12 12" fill="none" aria-hidden="true">
        <path d="M2.75 6h6.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
    </svg>
</span>
