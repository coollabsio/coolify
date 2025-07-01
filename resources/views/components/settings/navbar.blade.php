<div class="pb-5">
    <h1>Settings</h1>
    <div class="subtitle">Instance wide settings for Coolify.</div>
    <div class="navbar-main">
        <nav class="flex items-center gap-6 min-h-10 whitespace-nowrap">
            <a class="{{ request()->routeIs('settings.index') ? 'dark:text-white' : '' }}"
                href="{{ route('settings.index') }}">
                Configuration
            </a>
            <a class="{{ request()->routeIs('settings.backup') ? 'dark:text-white' : '' }}"
                href="{{ route('settings.backup') }}">
                Backup
            </a>
            <a class="{{ request()->routeIs('settings.email') ? 'dark:text-white' : '' }}"
                href="{{ route('settings.email') }}">
                Transactional Email
            </a>
            <a class="{{ request()->routeIs('settings.oauth') ? 'dark:text-white' : '' }}"
                href="{{ route('settings.oauth') }}">
                OAuth
            </a>
            <div class="flex-1"></div>
        </nav>
    </div>
</div>
