<x-layout>
    <h1>Settings</h1>

    <h3>General</h3>
    <livewire:settings.form :settings="$settings" />

    <div class="h-12"></div>

    <h3>Notifications</h3>
    <livewire:settings.discord-notifications :settings="$settings" />
    <livewire:settings.email-notifications :settings="$settings" />

    <div class="h-12"></div>
</x-layout>
