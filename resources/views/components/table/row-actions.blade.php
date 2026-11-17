{{-- Row action menu for tables: a "..." icon button at the far right of the row.
     Put <x-table.row-action> items in the default slot. Put destructive items in
     the danger slot: they show in red, below a divider. A click on any item
     closes the menu, also when the item opens a modal. --}}
@props([
    'label' => 'Actions',
])

<x-table.dropdown panel-class="w-44!" role="menu">
    <x-slot:trigger>
        <button type="button" class="icon-button" title="{{ $label }}" aria-label="{{ $label }}"
            aria-haspopup="menu" :aria-expanded="open">
            <x-reicon name="dots-horizontal" class="size-4" />
        </button>
    </x-slot:trigger>
    <div class="contents" @click="if ($event.target.closest('[role=menuitem]')) close()">
        {{ $slot }}
        @if (isset($danger) && $danger->isNotEmpty())
            @if ($slot->isNotEmpty())
                <div class="my-1 border-t border-neutral-200 dark:border-white/[0.08]"></div>
            @endif
            <div class="row-actions-danger contents">
                {{ $danger }}
            </div>
        @endif
    </div>
</x-table.dropdown>
