<div>
    <x-slot:title>
        {{ data_get_str($application, 'name')->limit(10) }} > Volume Backups | Coolify
    </x-slot>
    <h1>Volume Backups</h1>
    <livewire:project.shared.configuration-checker :resource="$application" />
    <livewire:project.application.heading :application="$application" />

    <livewire:project.shared.storages.volume-backups :storage="$backup->volume" :resource="$application"
        wire:key="volume-backup-{{ $backup->uuid }}" />
</div>
