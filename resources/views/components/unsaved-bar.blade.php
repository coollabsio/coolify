@props([
    'action' => 'submit',
    'label' => "You have changes that haven't been saved yet.",
])

{{-- Floating "unsaved changes" pill (bottom center). Reveals itself via
     Livewire's wire:dirty whenever the surrounding component has un-saved
     model changes. --}}
<div wire:dirty.class.remove="opacity-0 translate-y-6 pointer-events-none"
    class="pointer-events-none fixed bottom-6 left-1/2 z-[80] flex -translate-x-1/2 translate-y-6 items-center gap-4 rounded-2xl border border-white/10 bg-surface py-2 pr-2 pl-5 opacity-0 shadow-modal transition-all duration-200 ease-out sm:gap-8">
    <span class="whitespace-nowrap text-[13px] font-semibold text-fg">{{ $label }}</span>
    <div class="flex items-center gap-2">
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
