<div class="flex flex-col items-start gap-2 min-w-fit">
    <a class="menu-item {{ $activeMenu === 'general' ? 'menu-item-active' : '' }}"
        href="{{ route('settings.index') }}" wire:navigate.hover>General</a>
    <a class="menu-item {{ $activeMenu === 'advanced' ? 'menu-item-active' : '' }}"
        href="{{ route('settings.advanced') }}" wire:navigate.hover>Advanced</a>
    <a class="menu-item {{ $activeMenu === 'updates' ? 'menu-item-active' : '' }}"
        href="{{ route('settings.updates') }}" wire:navigate.hover>Updates</a>
</div>
