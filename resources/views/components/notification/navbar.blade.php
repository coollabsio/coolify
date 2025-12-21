<div class="pb-6">
    <h1>{{ __('notification.title') }}</h1>
    <div class="subtitle">{{ __('shared.get_notified') }}</div>
    <div class="navbar-main">
        <nav class="flex items-center gap-6 min-h-10">
            <a class="{{ request()->routeIs('notifications.email') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('notifications.email') }}">
                <button>{{ __('notification.email') }}</button>
            </a>
            <a class="{{ request()->routeIs('notifications.discord') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('notifications.discord') }}">
                <button>{{ __('notification.discord') }}</button>
            </a>
            <a class="{{ request()->routeIs('notifications.telegram') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('notifications.telegram') }}">
                <button>{{ __('notification.telegram') }}</button>
            </a>
            <a class="{{ request()->routeIs('notifications.slack') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('notifications.slack') }}">
                <button>{{ __('notification.slack') }}</button>
            </a>
            <a class="{{ request()->routeIs('notifications.pushover') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('notifications.pushover') }}">
                <button>{{ __('notification.pushover') }}</button>
            </a>
            <a class="{{ request()->routeIs('notifications.webhook') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('notifications.webhook') }}">
                <button>{{ __('notification.webhook') }}</button>
            </a>
        </nav>
    </div>
</div>
