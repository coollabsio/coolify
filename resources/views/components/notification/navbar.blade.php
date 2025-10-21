<div class="pb-6">
    <h1>Notifications</h1>
    <div class="subtitle">Get notified about your infrastructure.</div>
    <div class="navbar-main">
        <nav class="flex items-center gap-6 min-h-10">
            <a class="{{ request()->routeIs('notifications.email') ? 'dark:text-white' : '' }}"
                href="{{ route('notifications.email') }}" wire:navigate.hover>
                <button>Email</button>
            </a>
            <a class="{{ request()->routeIs('notifications.discord') ? 'dark:text-white' : '' }}"
                href="{{ route('notifications.discord') }}" wire:navigate.hover>
                <button>Discord</button>
            </a>
            <a class="{{ request()->routeIs('notifications.telegram') ? 'dark:text-white' : '' }}"
                href="{{ route('notifications.telegram') }}" wire:navigate.hover>
                <button>Telegram</button>
            </a>
            <a class="{{ request()->routeIs('notifications.slack') ? 'dark:text-white' : '' }}"
                href="{{ route('notifications.slack') }}" wire:navigate.hover>
                <button>Slack</button>
            </a>
            <a class="{{ request()->routeIs('notifications.pushover') ? 'dark:text-white' : '' }}"
                href="{{ route('notifications.pushover') }}" wire:navigate.hover>
                <button>Pushover</button>
            </a>
            <a class="{{ request()->routeIs('notifications.webhook') ? 'dark:text-white' : '' }}"
                href="{{ route('notifications.webhook') }}" wire:navigate.hover>
                <button>Webhook</button>
            </a>
        </nav>
    </div>
</div>
