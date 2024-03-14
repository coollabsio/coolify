<div class="pb-6">
    <div class="flex items-end gap-2">
        <h1>Team Notifications</h1>
    </div>
    <nav class="flex pt-2 pb-10">
        <ol class="inline-flex items-center">
            <li>
                <div class="flex items-center">
                    <span>Currently active team: <span
                            class="text-warning">{{ session('currentTeam.name') }}</span></span>
                </div>
            </li>
        </ol>
    </nav>
    <nav class="navbar-main">
        <a class="{{ request()->routeIs('notification.index') ? 'text-white' : '' }}"
            href="{{ route('notification.index') }}">
            <button>General</button>
        </a>
        <div class="flex-1"></div>
        <div class="-mt-9">
            <livewire:switch-team />
        </div>
    </nav>
</div>
