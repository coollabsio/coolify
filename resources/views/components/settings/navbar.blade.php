<div class="pb-6">
    <h1>Settings</h1>
    <nav class="flex pt-2 pb-10 text-sm">
        <ol class="inline-flex items-center">
            <li class="inline-flex items-center">
                Instance wide settings for Coolify.
            </li>
        </ol>
    </nav>
    <nav class="flex items-center gap-4 py-2 border-b-2 border-solid border-coolgray-200">
        <a class="{{ request()->routeIs('settings.configuration') ? 'text-white' : '' }}"
            href="{{ route('settings.configuration') }}">
            <button>Configuration</button>
        </a>
        <a class="{{ request()->routeIs('settings.emails') ? 'text-white' : '' }}" href="{{ route('settings.emails') }}">
            <button>Emails</button>
        </a>
        <div class="flex-1"></div>
        <livewire:switch-team />
    </nav>
</div>
