@props(['menu', 'items'])
@php
    // $items: the pages that this resource menu shows, as
    // array<int, array{key: string, label: string, icon: string, href: string, active: bool, navigate?: bool}>.
    // The group shows only starred pages from this list, so a star for a page that this resource
    // does not have (or that the user cannot open) never shows.
    $items = collect($items)->map(fn (array $item): array => [
        ...$item,
        'navigate' => ($item['navigate'] ?? true) && wireNavigate() !== '',
    ])->values();
@endphp
<div x-data="starredMenuGroup(@js($menu), @js($items->pluck('key')))" x-show="starred.length > 0" x-cloak
    class="starred-menu"
    data-starred-menu="{{ json_encode(['menu' => $menu, 'items' => $items->map(fn (array $item): array => collect($item)->only(['key', 'label', 'href', 'active', 'navigate'])->all())]) }}">
    <div class="starred-menu-title">
        <span class="flex items-center gap-1.5">
            <x-reicon name="star-filled" class="size-3 text-warning" />
            Starred
        </span>
        <span x-text="starred.length"></span>
    </div>
    <div class="flex flex-col gap-0.5" role="list" aria-label="Starred pages">
        @foreach ($items as $item)
            <div role="listitem" class="starred-menu-row starred-menu-item" draggable="true"
                x-show="starred.includes(@js($item['key']))" :style="{ order: position(@js($item['key'])) }"
                :class="dragging === @js($item['key']) && 'is-dragging'"
                @dragstart="dragStart($event, @js($item['key']))" @dragover.prevent="dragOver(@js($item['key']))"
                @drop.prevent @dragend="dragEnd()">
                <a @class(['menu-item', 'menu-item-active' => $item['active']]) href="{{ $item['href'] }}" draggable="false"
                    @if ($item['navigate']) wire:navigate @endif
                    title="{{ $item['label'] }} (drag or press Alt+Up/Down to reorder)"
                    @keydown.alt.up.prevent="moveBy(@js($item['key']), -1)"
                    @keydown.alt.down.prevent="moveBy(@js($item['key']), 1)">
                    <x-reicon :name="$item['icon']" class="menu-item-icon" />
                    <span class="menu-item-label">{{ $item['label'] }}</span>
                </a>
                <x-starred-menu.toggle :menu="$menu" :page="$item['key']" :label="$item['label']" />
            </div>
        @endforeach
    </div>
    <div class="my-2 border-t border-neutral-200 dark:border-white/[0.06]" aria-hidden="true"></div>
</div>
