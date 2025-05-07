<div class="pb-6">
    <div class="flex items-end gap-2">
        <h1>Team</h1>
        <x-modal-input buttonTitle="+ Add" title="New Team">
            <livewire:team.create />
        </x-modal-input>
    </div>
    <div class="subtitle">Team wide configurations.</div>
    <div class="navbar-main">
        <nav class="flex items-center gap-6 min-h-10">
            <a class="{{ request()->routeIs('team.index') ? 'dark:text-white' : '' }}" wire:navigate
                href="{{ route('team.index') }}">
                <button>General</button>
            </a>
            <a class="{{ request()->routeIs('team.member.index') ? 'dark:text-white' : '' }}" wire:navigate
                href="{{ route('team.member.index') }}">
                <button>Members</button>
            </a>
            @if (isInstanceAdmin())
                <a class="{{ request()->routeIs('team.admin-view') ? 'dark:text-white' : '' }}" wire:navigate
                    href="{{ route('team.admin-view') }}">
                    <button>Admin View</button>
                </a>
            @endif
            <div class="flex-1"></div>
        </nav>
    </div>
</div>
