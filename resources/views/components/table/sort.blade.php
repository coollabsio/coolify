<div class="table-sort relative" x-data="{ open: false }" @keydown.escape.window="open = false">
    <button type="button" class="button" @click="open = !open" @click.outside="open = false"
        aria-haspopup="listbox" :aria-expanded="open">
        <x-reicon name="sort-direction" class="size-3.5" />
        Sort
    </button>
    <div class="listbox-panel left-auto! right-0! z-[90]! min-w-44!" x-show="open" x-cloak role="listbox">
        {{ $slot }}
    </div>
</div>
