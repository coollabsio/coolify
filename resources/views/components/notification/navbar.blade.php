<div class="pb-6">
    <h1>Notifications</h1>
    <div class="subtitle">Get notified about your infrastructure.</div>
    <nav class="navbar-main">
        <a class="{{ request()->routeIs('notification.email') ? 'dark:text-white' : '' }}"
            href="{{ route('notification.email') }}">
            <button>Email</button>
        </a>
        <a class="{{ request()->routeIs('notification.telegram') ? 'dark:text-white' : '' }}"
            href="{{ route('notification.telegram') }}">
            <button>Telegram</button>
        </a>
        <a class="{{ request()->routeIs('notification.discord') ? 'dark:text-white' : '' }}"
            href="{{ route('notification.discord') }}">
            <button>Discord</button>
        </a>
    </nav>
</div>
