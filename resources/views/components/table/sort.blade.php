<div class="table-sort">
    <x-table.dropdown panel-class="w-44!">
        <x-slot:trigger>
            <button type="button" class="button" aria-haspopup="listbox" :aria-expanded="open">
                <x-reicon name="sort-direction" class="size-3.5" />
                Sort
            </button>
        </x-slot:trigger>
        {{ $slot }}
    </x-table.dropdown>
</div>
