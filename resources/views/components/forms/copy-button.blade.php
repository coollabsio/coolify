@props(['text', 'label' => null])

<div class="w-full" x-data="{ copied: false, isSecure: window.isSecureContext }">
    @if ($label)
        <label class="flex gap-1 items-center mb-1 text-sm font-medium">{{ $label }}</label>
    @endif
    <div class="relative">
        <input type="text" value="{{ $text }}" class="input pr-10"
            @keydown.prevent @paste.prevent @cut.prevent @drop.prevent
            @focus="$event.target.select()">
    <button
        x-show="isSecure"
        @click.prevent="copied = true; navigator.clipboard.writeText({{ Js::from($text) }}); setTimeout(() => copied = false, 1000)"
        class="absolute right-2 top-1/2 -translate-y-1/2 p-1.5 text-gray-400 hover:text-gray-300 transition-colors"
        title="Copy to clipboard">
        <svg x-show="!copied" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
        </svg>
        <svg x-show="copied" class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
    </button>
    </div>
</div>
