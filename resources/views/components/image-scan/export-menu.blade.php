{{-- Export menu of a vulnerability list. The Livewire component has copyList('markdown'|'ids') and
     downloadList('csv'|'json'), both for the rows that the list shows on all its pages. --}}
@props([
    'count',
    'noun' => 'finding',
    'withIds' => true,
])

@php
    $countText = number_format($count).' '.str($noun)->plural($count);
    $copy = fn (string $format, string $done) => "close();
        if (! navigator.clipboard) {
            Livewire.dispatch('error', ['The clipboard needs HTTPS or localhost.']);
            return;
        }
        window.copyTextToClipboard(\$wire.copyList('{$format}'))
            .then(() => Livewire.dispatch('success', [".\Illuminate\Support\Js::from($done)."]))
            .catch(() => Livewire.dispatch('error', ['Could not copy the list.']))";
@endphp

<x-table.dropdown role="menu" panel-class="w-64! p-1!">
    <x-slot:trigger>
        <button type="button" class="button normal-case!" aria-haspopup="menu" :aria-expanded="open"
            @disabled($count === 0)>
            <x-reicon name="download" class="size-3.5" />
            Export
            <x-reicon name="chevron-down" class="size-3.5" />
        </button>
    </x-slot:trigger>
    <p class="px-2 pb-1.5 pt-1 text-[11px] leading-4 text-neutral-500 dark:text-fg-faint">
        The {{ $countText }} in this list, from all pages.
    </p>
    <button type="button" class="listbox-option" role="menuitem"
        x-on:click="{{ $copy('markdown', "Copied {$countText} as Markdown.") }}">
        <span class="flex-1 text-left">Copy as Markdown table</span>
    </button>
    @if ($withIds)
        <button type="button" class="listbox-option" role="menuitem"
            x-on:click="{{ $copy('ids', "Copied the IDs of {$countText}.") }}">
            <span class="flex-1 text-left">Copy IDs</span>
        </button>
    @endif
    <div class="my-1 border-t border-neutral-200 dark:border-white/[0.08]" role="separator"></div>
    <button type="button" class="listbox-option" role="menuitem" wire:click="downloadList('csv')"
        x-on:click="close()">
        <span class="flex-1 text-left">Download CSV</span>
    </button>
    <button type="button" class="listbox-option" role="menuitem" wire:click="downloadList('json')"
        x-on:click="close()">
        <span class="flex-1 text-left">Download JSON</span>
    </button>
</x-table.dropdown>
