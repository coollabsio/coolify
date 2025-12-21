<div class="pb-6">
    <div class="flex items-end gap-2">
        <h1>{{ __('teams.team') }}</h1>
        <x-modal-input buttonTitle="{{ __('button.add') }}" title="{{ __('modal.new_team') }}">
            <livewire:team.create />
        </x-modal-input>
    </div>
    <div class="subtitle">{{ __('teams.team_wide_configurations') }}</div>
    <div class="navbar-main">
        <nav class="flex items-center gap-6 min-h-10">
            <a class="{{ request()->routeIs('team.index') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('team.index') }}">
                {{ __('teams.general') }}
            </a>
            <a class="{{ request()->routeIs('team.member.index') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                href="{{ route('team.member.index') }}">
                {{ __('teams.members') }}
            </a>
            @if (isInstanceAdmin())
                <a class="{{ request()->routeIs('team.admin-view') ? 'dark:text-white' : '' }}" {{ wireNavigate() }}
                    href="{{ route('team.admin-view') }}">
                    {{ __('teams.admin_view') }}
                </a>
            @endif
            <div class="flex-1"></div>
        </nav>
    </div>
</div>
