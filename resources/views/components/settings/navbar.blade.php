<div class="pb-5">
    <h1>Settings</h1>
    <div class="subtitle">Instance wide settings for Coolify.</div>
    <div class="navbar-main">
        <nav class="flex items-center gap-6 min-h-10 whitespace-nowrap">
            <a class="nav-tab {{ request()->routeIs('settings.index') ? 'nav-tab-active' : '' }}" {{ wireNavigate() }}
                href="{{ route('settings.index') }}">
                Configuration
            </a>
            <a class="nav-tab {{ request()->routeIs('settings.backup') ? 'nav-tab-active' : '' }}" {{ wireNavigate() }}
                href="{{ route('settings.backup') }}">
                Backup
            </a>
            <a class="nav-tab {{ request()->routeIs('settings.email') ? 'nav-tab-active' : '' }}" {{ wireNavigate() }}
                href="{{ route('settings.email') }}">
                Transactional Email
            </a>
            <a class="nav-tab {{ request()->routeIs('settings.oauth') ? 'nav-tab-active' : '' }}" {{ wireNavigate() }}
                href="{{ route('settings.oauth') }}">
                OAuth
            </a>
            <div class="flex-1"></div>
        </nav>
    </div>
</div>
