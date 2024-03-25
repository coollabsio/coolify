<div class="pb-6">
    <div class="flex items-end gap-2">
        <h1>Team</h1>
        <x-modal-input buttonTitle="+ Add" title="New Team">
            <livewire:team.create/>
        </x-modal-input>
    </div>
    <div class="subtitle">Team settings & shared environment variables.</div>
    <nav class="navbar-main">
        <a class="{{ request()->routeIs('team.index') ? 'dark:text-white' : '' }}" href="{{ route('team.index') }}">
            <button>General</button>
        </a>
        <a class="{{ request()->routeIs('team.member.index') ? 'dark:text-white' : '' }}"
            href="{{ route('team.member.index') }}">
            <button>Members</button>
        </a>
        <a class="{{ request()->routeIs('team.storage.index') ? 'dark:text-white' : '' }}"
            href="{{ route('team.storage.index') }}">
            <button>S3 Storages</button>
        </a>
        <a class="{{ request()->routeIs('team.shared-variables.index') ? 'dark:text-white' : '' }}"
            href="{{ route('team.shared-variables.index') }}">
            <button>Shared Variables</button>
        </a>
        <div class="flex-1"></div>
    </nav>
</div>
