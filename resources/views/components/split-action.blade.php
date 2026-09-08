<div {{ $attributes->class(['split-action relative']) }} x-data="{ open: false }"
    x-effect="$dispatch('resource-actions-toggled', { open })" @click.outside="open = false"
    @keydown.escape.window="open = false">
    <button type="button" {{ $main->attributes->class(['split-action-main']) }}>
        {{ $main }}
    </button>
    @if ($slot->hasActualContent())
        <button type="button" class="split-action-caret" @click="open = !open" :aria-expanded="open"
            aria-haspopup="menu" aria-label="More actions">
            <span class="inline-flex transition-transform" :class="open && 'rotate-180'">
                <x-reicon name="chevron-down" class="size-3" />
            </span>
        </button>
        <div x-cloak x-show="open" x-transition.origin.top
            class="listbox-panel right-0! xl:left-auto! xl:min-w-60! xl:max-w-96!" role="menu">
            {{ $slot }}
        </div>
    @endif
</div>
