@props([
    'action' => 'submit',
    'label' => "You have changes that haven't been saved yet.",
    // Optional comma-separated Livewire properties. When set, the bar only
    // appears when those fields differ from the last server snapshot — not on
    // incidental component state (e.g. $wire.set from x-init, display-only props).
    'targets' => null,
])

{{-- Floating "unsaved changes" pill (bottom center). Reveals itself via
     Livewire's wire:dirty whenever the surrounding component has un-saved
     model changes.

     Instant-save controls (listboxes with onChange, $wire.set + submit, etc.)
     briefly mark the component dirty before the round-trip clears it. A delayed
     show + hide-while-loading swallows that flash so the bar only appears for
     real pending edits. Hide has no delay so Save still feels snappy.

     Mobile: stacked layout (full label, buttons on the next line) inset from
     the viewport edges so body overflow-x-clip cannot clip it.
     Desktop: compact single-row centered pill. --}}
<div x-data="{
    keyboardInset: 0,
    updateKeyboardInset: null,
    init() {
        this.updateKeyboardInset = () => {
            const viewport = window.visualViewport;
            this.keyboardInset = window.innerWidth < 640 && viewport
                ? Math.max(0, window.innerHeight - viewport.height - viewport.offsetTop)
                : 0;
        };

        this.updateKeyboardInset();
        window.visualViewport?.addEventListener('resize', this.updateKeyboardInset);
        window.visualViewport?.addEventListener('scroll', this.updateKeyboardInset);
        window.addEventListener('resize', this.updateKeyboardInset);
    },
    destroy() {
        window.visualViewport?.removeEventListener('resize', this.updateKeyboardInset);
        window.visualViewport?.removeEventListener('scroll', this.updateKeyboardInset);
        window.removeEventListener('resize', this.updateKeyboardInset);
    },
}" x-bind:style="`--keyboard-inset: ${keyboardInset}px`" wire:dirty.class="is-dirty"
    wire:loading.class="is-saving"
    @keydown.enter.window="
        if ($el.classList.contains('is-dirty') &&
            !$event.repeat &&
            !$event.isComposing &&
            !$event.shiftKey &&
            !$event.ctrlKey &&
            !$event.altKey &&
            !$event.metaKey &&
            !$event.target.closest('textarea, button, a, select, [contenteditable=true]')) {
            $event.preventDefault();
            $wire.{{ $action }}();
        }
    "
    @if ($targets) wire:target="{{ $targets }}" @endif
    class="pointer-events-none fixed inset-x-3 bottom-[calc(var(--keyboard-inset,0px)+max(1.5rem,env(safe-area-inset-bottom,0px)+0.75rem))] z-[1000] flex max-w-full translate-y-6 scale-95 flex-col items-stretch gap-2 rounded-2xl border border-neutral-200 bg-white py-2.5 pr-2.5 pl-4 opacity-0 shadow-modal transition-[opacity,transform,scale] duration-300 ease-[cubic-bezier(0.16,1,0.3,1)] delay-0 dark:border-white/10 dark:bg-surface [&.is-dirty]:pointer-events-auto [&.is-dirty]:translate-y-0 [&.is-dirty]:scale-100 [&.is-dirty]:opacity-100 [&.is-dirty]:delay-300 [&.is-saving]:pointer-events-none [&.is-saving]:translate-y-6 [&.is-saving]:scale-95 [&.is-saving]:opacity-0 [&.is-saving]:duration-200 [&.is-saving]:ease-in [&.is-saving]:delay-0 sm:inset-x-auto sm:left-1/2 sm:bottom-6 sm:w-max sm:max-w-none sm:-translate-x-1/2 sm:flex-row sm:items-center sm:gap-8 sm:py-2 sm:pl-5 sm:pr-2">
    <span class="text-[13px] font-semibold leading-snug text-neutral-800 dark:text-fg sm:whitespace-nowrap">{{ $label }}</span>
    <div class="flex shrink-0 items-center justify-end gap-2">
        <button type="button" onclick="window.location.reload()"
            class="h-8 rounded-lg bg-neutral-100 px-3.5 text-[13px] font-medium text-neutral-700 transition-colors hover:bg-neutral-200 dark:bg-white/[0.07] dark:text-fg dark:hover:bg-white/[0.12]">
            Reset
        </button>
        <button type="button" wire:click="{{ $action }}" wire:loading.attr="disabled"
            class="button-highlighted flex h-8 items-center gap-2 rounded-lg px-4 text-[13px] font-semibold transition-[transform,background-color] active:scale-[0.98]">
            <span>Save changes</span>
            <kbd
                class="rounded border border-current/20 bg-current/10 px-1.5 py-0.5 text-[10px] leading-none font-medium text-current">Enter</kbd>
        </button>
    </div>
</div>
