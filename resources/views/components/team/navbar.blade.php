<div class="pb-6">
    <h1>Team</h1>
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
        <a class="{{ request()->routeIs('team.index') ? 'text-white' : '' }}" href="{{ route('team.index') }}">
            <button>General</button>
        </a>
        <a class="{{ request()->routeIs('team.members') ? 'text-white' : '' }}" href="{{ route('team.members') }}">
            <button>Members</button>
        </a>
        <a class="{{ request()->routeIs('team.storages.all') ? 'text-white' : '' }}"
            href="{{ route('team.storages.all') }}">
            <button>S3 Storages</button>
        </a>
        <a class="{{ request()->routeIs('team.notifications') ? 'text-white' : '' }}"
            href="{{ route('team.notifications') }}">
            <button>Notifications</button>
        </a>
        <div class="flex-1"></div>
        <div class="-mt-9">
            <livewire:switch-team />
        </div>
    </nav>
</div>
