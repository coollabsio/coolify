@props(['name'])

<div class="flex items-center gap-1.5 px-2 h-8 text-sm rounded-sm border-2 dark:bg-coolgray-100 dark:border-coolgray-300 border-neutral-200" x-data="{ copied: false }">
    <span class="text-neutral-400 whitespace-nowrap">Container name:</span>
    <span class="dark:text-neutral-300">{{ $name }}</span>
    <button
        x-show="window.isSecureContext"
        @click.prevent="copied = true; navigator.clipboard.writeText({{ Js::from($name) }}); setTimeout(() => copied = false, 1500)"
        class="p-0.5 text-neutral-400 hover:text-neutral-200 transition-colors"
        title="Copy container name">
        <svg x-show="!copied" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
        </svg>
        <svg x-show="copied" x-cloak class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
    </button>
</div>
