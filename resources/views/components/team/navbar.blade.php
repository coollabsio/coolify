<div class="pb-6">
    <div class="form-section-title mb-6">
        <h1>Team</h1>
        <div class="flex items-center gap-2">
            <x-modal-input buttonTitle="+ Add" title="New Team">
                <livewire:team.create />
            </x-modal-input>
        </div>
    </div>
    <p class="text-sm text-neutral-500 dark:text-neutral-400 -mt-4 mb-4">Team wide configurations.</p>
    <div class="navbar-main">
        <nav class="flex items-center gap-6 min-h-10">
            <a class="nav-tab {{ request()->routeIs('team.index') ? 'nav-tab-active' : '' }}" {{ wireNavigate() }}
                href="{{ route('team.index') }}">
                General
            </a>
            <a class="nav-tab {{ request()->routeIs('team.member.index') ? 'nav-tab-active' : '' }}" {{ wireNavigate() }}
                href="{{ route('team.member.index') }}">
                Members
            </a>
            @if (isInstanceAdmin())
                <a class="nav-tab {{ request()->routeIs('team.admin-view') ? 'nav-tab-active' : '' }}" {{ wireNavigate() }}
                    href="{{ route('team.admin-view') }}">
                    Admin View
                </a>
            @endif
            <div class="flex-1"></div>
        </nav>
    </div>
</div>
