<div class="flex flex-col items-start gap-2 min-w-fit">
    <a class="menu-item {{ $activeMenu === 'general' ? 'menu-item-active' : '' }}"
        href="{{ route('settings.index') }}">General</a>
    <a class="menu-item {{ $activeMenu === 'advanced' ? 'menu-item-active' : '' }}"
        href="{{ route('settings.advanced') }}">Advanced</a>
    <a class="menu-item {{ $activeMenu === 'updates' ? 'menu-item-active' : '' }}"
        href="{{ route('settings.updates') }}">Updates</a>
</div>
