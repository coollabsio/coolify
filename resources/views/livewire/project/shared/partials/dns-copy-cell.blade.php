@php
    $break = $break ?? false;
    $label = $label ?? 'Copy';
@endphp
<div class="flex min-w-0 items-center gap-1.5"
    x-data="{
        copied: false,
        async copy(text) {
            try {
                if (navigator.clipboard?.writeText) {
                    await navigator.clipboard.writeText(text);
                } else {
                    const el = document.createElement('textarea');
                    el.value = text;
                    el.setAttribute('readonly', '');
                    el.style.position = 'fixed';
                    el.style.left = '-9999px';
                    document.body.appendChild(el);
                    el.select();
                    document.execCommand('copy');
                    document.body.removeChild(el);
                }
                this.copied = true;
                setTimeout(() => this.copied = false, 1000);
            } catch (e) {
                console.error('Copy failed', e);
            }
        }
    }">
    <span @class(['min-w-0', 'break-all' => $break])>{{ $text }}</span>
    <button type="button"
        @click.prevent.stop="copy(@js($text))"
        class="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-dim dark:hover:bg-coolgray-200 dark:hover:text-white"
        title="{{ $label }}"
        aria-label="{{ $label }}">
        <svg x-show="!copied" class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
        </svg>
        <svg x-show="copied" x-cloak class="size-3.5 text-green-500" fill="none" stroke="currentColor"
            viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
    </button>
</div>
