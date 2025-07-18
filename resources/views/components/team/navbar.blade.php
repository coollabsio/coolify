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
            <a class="{{ request()->routeIs('team.index') ? 'dark:text-white' : '' }}" href="{{ route('team.index') }}">
                General
            </a>
            <a class="{{ request()->routeIs('team.member.index') ? 'dark:text-white' : '' }}"
                href="{{ route('team.member.index') }}">
                Members
            </a>
            @if (isInstanceAdmin())
                <a class="{{ request()->routeIs('team.admin-view') ? 'dark:text-white' : '' }}"
                    href="{{ route('team.admin-view') }}">
                    Admin View
                </a>
            @endif
            <div class="flex-1"></div>
        </nav>
    </div>
</div>
