<x-layout>
    <h1>Team</h1>
    <p>Current Team: {{ session('currentTeam.name') }}</p>
    @if (auth()->user()->otherTeams()->count() > 0)
        <livewire:switch-team />
    @endif
    <h2>Notifications</h2>
    <livewire:notifications.test :model="session('currentTeam')" />
    <livewire:notifications.email-settings :model="session('currentTeam')" />
    <livewire:notifications.discord-settings :model="session('currentTeam')" />
    <div class="h-12"></div>
</x-layout>
