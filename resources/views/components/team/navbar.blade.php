<div class="pb-6">
    <h1>Team</h1>
    <nav class="flex pt-2 pb-10 text-sm">
        <ol class="inline-flex items-center">
            <li class="inline-flex items-center">
                Currently Active Team
            </li>
            <li>
                <div class="flex items-center">
                    <svg aria-hidden="true" class="w-4 h-4 mx-1 font-bold text-warning" fill="currentColor"
                        viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd"
                            d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                            clip-rule="evenodd"></path>
                    </svg>
                    <span class="font-bold text-warning">{{ session('currentTeam.name') }}</span>
                </div>
            </li>
        </ol>
    </nav>
    <nav class="flex items-end gap-4 py-2 border-b-2 border-solid border-coolgray-200">
        <a class="{{ request()->routeIs('team.show') ? 'text-white' : '' }}" href="{{ route('team.show') }}">
            <button>Members</button>
        </a>
        <a class="{{ request()->routeIs('team.notifications') ? 'text-white' : '' }}"
            href="{{ route('team.notifications') }}">
            <button>Notifications</button>
        </a>
        <div class="flex-1"></div>
        <livewire:switch-team />
    </nav>
</div>
