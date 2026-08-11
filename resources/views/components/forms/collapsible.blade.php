@props([
    'title' => 'Advanced settings',
    'contentClass' => '',
])

<div x-data="{ open: false }" {{ $attributes->class(['flex flex-col gap-4']) }}>
    <button type="button" x-on:click="open = !open"
        class="flex items-center gap-2 text-left text-sm font-medium hover:underline" :aria-expanded="open">
        <svg class="size-4 transition-transform" x-bind:class="open && 'rotate-90'" viewBox="0 0 20 20"
            fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd"
                d="M7.21 14.77a.75.75 0 0 1 .02-1.06L11.168 10 7.23 6.29a.75.75 0 1 1 1.04-1.08l4.5 4.25a.75.75 0 0 1 0 1.08l-4.5 4.25a.75.75 0 0 1-1.06-.02Z"
                clip-rule="evenodd" />
        </svg>
        {{ $title }}
    </button>

    <div x-show="open" x-cloak
        class="rounded-lg border border-neutral-200 p-4 dark:border-coolgray-400 {{ $contentClass }}">
        {{ $slot }}
    </div>
</div>
