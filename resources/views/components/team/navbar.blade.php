<div class="pb-6">
    <div class="flex items-end gap-2">
        <h1>Team</h1>
        <a  href="/team/new"><x-forms.button>+ New Team</x-forms.button></a>
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
        <a class="{{ request()->routeIs('team.index') ? 'text-white' : '' }}" href="{{ route('team.index') }}">
            <button>General</button>
        </a>
        <a class="{{ request()->routeIs('team.member.index') ? 'text-white' : '' }}" href="{{ route('team.member.index') }}">
            <button>Members</button>
        </a>
        <a class="{{ request()->routeIs('team.storage.index') ? 'text-white' : '' }}"
            href="{{ route('team.storage.index') }}">
            <button>S3 Storages</button>
        </a>
        <a class="{{ request()->routeIs('team.notification.index') ? 'text-white' : '' }}"
            href="{{ route('team.notification.index') }}">
            <button>Notifications</button>
        </a>
        <a  class="{{ request()->routeIs('team.shared-variables.index') ? 'text-white' : '' }}"
            href="{{ route('team.shared-variables.index') }}">
            <button>Shared Variables</button>
        </a>
        <div class="flex-1"></div>
        <div class="-mt-9">
            <livewire:switch-team />
        </div>
    </nav>
</div>
