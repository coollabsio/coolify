<div class="pb-6">
    <div class="flex items-end gap-2">
        <h1>Team</h1>
        <x-slide-over>
            <x-slot:title>New Team</x-slot:title>
            <x-slot:content>
                <livewire:team.create/>
            </x-slot:content>
            <button @click="slideOverOpen=true" class="button">+
                Add</button>
        </x-slide-over>
    </div>
    <div class="subtitle">Team settings & shared environment variables.</div>
    <nav class="navbar-main">
        <a class="{{ request()->routeIs('team.index') ? 'text-white' : '' }}" href="{{ route('team.index') }}">
            <button>General</button>
        </a>
        <a class="{{ request()->routeIs('team.member.index') ? 'text-white' : '' }}"
            href="{{ route('team.member.index') }}">
            <button>Members</button>
        </a>
        <a class="{{ request()->routeIs('team.storage.index') ? 'text-white' : '' }}"
            href="{{ route('team.storage.index') }}">
            <button>S3 Storages</button>
        </a>
        <a class="{{ request()->routeIs('team.shared-variables.index') ? 'text-white' : '' }}"
            href="{{ route('team.shared-variables.index') }}">
            <button>Shared Variables</button>
        </a>
        <div class="flex-1"></div>
    </nav>
</div>
