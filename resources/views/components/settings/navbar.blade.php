<div class="pb-5">
    <h1>{{ __('common.settings') }}</h1>
    <div class="subtitle">{{ __('shared.instance_wide_settings') }}</div>
    <div class="navbar-main">
        <nav class="flex items-center gap-6 min-h-10 whitespace-nowrap">
            <a class="{{ request()->routeIs('settings.index') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('settings.index') }}">
                {{ __('menu.configuration') }}
            </a>
            <a class="{{ request()->routeIs('settings.backup') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('settings.backup') }}">
                {{ __('menu.backups') }}
            </a>
            <a class="{{ request()->routeIs('settings.email') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('settings.email') }}">
                {{ __('modal.send_test_email') }}
            </a>
            <a class="{{ request()->routeIs('settings.oauth') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('settings.oauth') }}">
                OAuth
            </a>
            <div class="flex-1"></div>
        </nav>
    </div>
</div>
