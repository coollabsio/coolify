<div class="pb-5">
    <h1>Settings</h1>
    <div class="pt-2 pb-10">Instance wide settings for Coolify.</div>
    <nav class="navbar-main">
        <a class="{{ request()->routeIs('settings.configuration') ? 'text-white' : '' }}"
           href="{{ route('settings.configuration') }}">
            <button>Configuration</button>
        </a>
        @if (is_cloud())
            <a class="{{ request()->routeIs('settings.license') ? 'text-white' : '' }}"
               href="{{ route('settings.license') }}">
                <button>Resale License</button>
            </a>
        @endif
        <div class="flex-1"></div>
    </nav>
</div>
