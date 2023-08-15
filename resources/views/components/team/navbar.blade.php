<div class="pb-6">
    <h1>Team</h1>
    <nav class="flex pt-2 pb-10">
        <ol class="inline-flex items-center">
            <li>
                <div class="flex items-center">
                    <span>Currently active team: {{ session('currentTeam.name') }}</span>
                </div>
            </li>
            @if (session('currentTeam.description'))
                <li class="inline-flex items-center">
                    <svg aria-hidden="true" class="w-4 h-4 mx-1 font-bold text-warning" fill="currentColor"
                        viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd"
                            d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                            clip-rule="evenodd"></path>
                    </svg>
                    <span class="truncate">{{ Str::limit(session('currentTeam.description'), 52) }}</span>
                </li>
            @endif
        </ol>
    </nav>
    <nav class="navbar-main">
        <a class="{{ request()->routeIs('team.show') ? 'text-white' : '' }}" href="{{ route('team.show') }}">
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
