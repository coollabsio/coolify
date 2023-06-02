<div class="pb-6">
    <h1>Team</h1>
    <div class="text-sm breadcrumbs pb-11">
        <ul>
            <li>{{ session('currentTeam.name') }}</li>
        </ul>
    </div>
    <nav class="flex items-center gap-4 py-2 border-b-2 border-solid border-coolgray-200">
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
