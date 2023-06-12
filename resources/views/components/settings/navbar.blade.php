<div class="pb-6">
    <h1>Settings</h1>
    <div class="pt-2 pb-10 text-sm">Instance wide settings for Coolify.</div>
    <nav class="flex items-end gap-4 py-2 border-b-2 border-solid border-coolgray-200">
        <a class="{{ request()->routeIs('settings.configuration') ? 'text-white' : '' }}"
            href="{{ route('settings.configuration') }}">
            <button>Configuration</button>
        </a>
        <a class="{{ request()->routeIs('settings.emails') ? 'text-white' : '' }}" href="{{ route('settings.emails') }}">
            <button>SMTP</button>
        </a>
        <div class="flex-1"></div>
    </nav>
</div>
