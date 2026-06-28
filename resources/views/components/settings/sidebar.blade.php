<div class="sub-menu-wrapper">
    <a class="sub-menu-item {{ $activeMenu === 'general' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
        href="{{ route('settings.index') }}"><span class="menu-item-label">General</span></a>
    <a class="sub-menu-item {{ $activeMenu === 'advanced' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
        href="{{ route('settings.advanced') }}"><span class="menu-item-label">Advanced</span></a>
    <a class="sub-menu-item {{ $activeMenu === 'updates' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
        href="{{ route('settings.updates') }}"><span class="menu-item-label">Updates</span></a>
</div>
