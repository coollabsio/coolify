<x-layout>
    <div>
        <h3>Current Team</h3>
        <p>Name: {{ session('currentTeam.name') }}</p>
        <livewire:switch-team />
        <div class="h-12"></div>
        <h3>Notifications</h3>
        <livewire:notifications.discord-settings :model="session('currentTeam')" />
        <livewire:notifications.email-settings :model="session('currentTeam')" />
        <div class="h-12"></div>
    </div>
    <livewire:switch-team>
</x-layout>
