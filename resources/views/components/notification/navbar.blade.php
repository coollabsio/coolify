<div class="pb-6">
    <h1>Notifications</h1>
    <div class="subtitle">Get notified about your infrastructure.</div>
    <div class="navbar-main">
        <nav class="flex items-center gap-3.5 min-h-10">
            <a class="nav-tab {{ request()->routeIs('notifications.email') ? 'nav-tab-active' : '' }}" {{ wireNavigate() }}
                href="{{ route('notifications.email') }}">
                Email
            </a>
            <a class="nav-tab {{ request()->routeIs('notifications.discord') ? 'nav-tab-active' : '' }}" {{ wireNavigate() }}
                href="{{ route('notifications.discord') }}">
                Discord
            </a>
            <a class="nav-tab {{ request()->routeIs('notifications.telegram') ? 'nav-tab-active' : '' }}" {{ wireNavigate() }}
                href="{{ route('notifications.telegram') }}">
                Telegram
            </a>
            <a class="nav-tab {{ request()->routeIs('notifications.slack') ? 'nav-tab-active' : '' }}" {{ wireNavigate() }}
                href="{{ route('notifications.slack') }}">
                Slack
            </a>
            <a class="nav-tab {{ request()->routeIs('notifications.pushover') ? 'nav-tab-active' : '' }}" {{ wireNavigate() }}
                href="{{ route('notifications.pushover') }}">
                Pushover
            </a>
            <a class="nav-tab {{ request()->routeIs('notifications.webhook') ? 'nav-tab-active' : '' }}" {{ wireNavigate() }}
                href="{{ route('notifications.webhook') }}">
                Webhook
            </a>
        </nav>
    </div>
</div>
