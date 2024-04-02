<div class="pb-5">
    <h1>Settings</h1>
    <div class="subtitle">Instance wide settings for Coolify.</div>
    <nav class="navbar-main">
        <a class="{{ request()->routeIs('settings.index') ? 'dark:text-white' : '' }}"
            href="{{ route('settings.index') }}">
            <button>Configuration</button>
        </a>
        @if (isCloud())
            <a class="{{ request()->routeIs('settings.license') ? 'dark:text-white' : '' }}"
                href="{{ route('settings.license') }}">
                <button>Resale License</button>
            </a>
        @endif
        <div class="flex-1"></div>
    </nav>
</div>
