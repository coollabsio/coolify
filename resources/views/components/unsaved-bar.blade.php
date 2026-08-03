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
     the viewport edges so body overflow-x-hidden cannot clip it.
     Desktop: compact single-row centered pill. --}}
<div wire:dirty.class="is-dirty"
    wire:loading.class="!opacity-0 !translate-y-6 !pointer-events-none"
    @if ($targets) wire:target="{{ $targets }}" @endif
    class="pointer-events-none fixed inset-x-3 bottom-[max(1.5rem,env(safe-area-inset-bottom,0px)+0.75rem)] z-[80] flex max-w-full translate-y-6 flex-col items-stretch gap-2 rounded-2xl border border-white/10 bg-surface py-2.5 pr-2.5 pl-4 opacity-0 shadow-modal transition-[opacity,transform] duration-200 ease-out delay-0 [&.is-dirty]:pointer-events-auto [&.is-dirty]:translate-y-0 [&.is-dirty]:opacity-100 [&.is-dirty]:delay-300 sm:inset-x-auto sm:left-1/2 sm:bottom-6 sm:w-max sm:max-w-none sm:-translate-x-1/2 sm:flex-row sm:items-center sm:gap-8 sm:py-2 sm:pl-5 sm:pr-2">
    <span class="text-[13px] font-semibold leading-snug text-fg sm:whitespace-nowrap">{{ $label }}</span>
    <div class="flex shrink-0 items-center justify-end gap-2">
        <button type="button" onclick="window.location.reload()"
            class="h-8 rounded-lg bg-white/[0.07] px-3.5 text-[13px] font-medium text-fg transition-colors hover:bg-white/[0.12]">
            Reset
        </button>
        <button type="button" wire:click="{{ $action }}" wire:loading.attr="disabled"
            class="h-8 rounded-lg bg-coollabs/10 px-4 text-[13px] font-semibold text-coollabs ring-1 ring-coollabs/25 transition-[transform,background-color] hover:bg-coollabs/15 active:scale-[0.98] dark:bg-warning/15 dark:text-warning dark:ring-warning/25 dark:hover:bg-warning/20">
            Save changes
        </button>
    </div>
</div>
