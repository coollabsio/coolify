<div class="flex flex-col items-start gap-2 min-w-fit">
    <a class="menu-item {{ $activeMenu === 'general' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
        href="{{ route('settings.index') }}">{{ __('settings.general') }}</a>
    <a class="menu-item {{ $activeMenu === 'advanced' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
        href="{{ route('settings.advanced') }}">{{ __('settings.advanced') }}</a>
    <a class="menu-item {{ $activeMenu === 'updates' ? 'menu-item-active' : '' }}" {{ wireNavigate() }}
        href="{{ route('settings.updates') }}">{{ __('settings.updates') }}</a>
</div>
