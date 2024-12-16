<div class="pb-6">
    <h1>Notifications</h1>
    <div class="subtitle">Get notified about your infrastructure.</div>
    <div class="navbar-main">
        <nav class="flex items-center gap-6 min-h-10">
            <a class="{{ request()->routeIs('notifications.email') ? 'dark:text-white' : '' }}"
                href="{{ route('notifications.email') }}">
                <button>Email</button>
            </a>
            <a class="{{ request()->routeIs('notifications.discord') ? 'dark:text-white' : '' }}"
                href="{{ route('notifications.discord') }}">
                <button>Discord</button>
            </a>
             <a class="{{ request()->routeIs('notifications.telegram') ? 'dark:text-white' : '' }}"
                href="{{ route('notifications.telegram') }}">
                <button>Telegram</button>
            </a>
            <a class="{{ request()->routeIs('notifications.slack') ? 'dark:text-white' : '' }}"
                href="{{ route('notifications.slack') }}">
                <button>Slack</button>
            </a>
            <a class="{{ request()->routeIs('notifications.pushover') ? 'dark:text-white' : '' }}"
                href="{{ route('notifications.pushover') }}">
                <button>Pushover</button>
            </a>
        </nav>
    </div>
</div>
