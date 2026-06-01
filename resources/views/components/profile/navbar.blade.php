<div class="pb-6">
    <h1>Profile</h1>
    <div class="subtitle">Your user profile settings.</div>
    <div class="navbar-main">
        <nav class="flex items-center gap-6 min-h-10">
            <a class="{{ request()->routeIs('profile') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('profile') }}">
                General
            </a>
            <a class="{{ request()->routeIs('profile.appearance') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('profile.appearance') }}">
                Appearance
            </a>
            <div class="flex-1"></div>
        </nav>
    </div>
</div>
