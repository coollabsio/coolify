<div>
    <x-slot:title>
        {{ data_get_str($application, 'name')->limit(10) }} > Backups | Coolify
    </x-slot>
    <h1>Backups</h1>
    <livewire:project.shared.configuration-checker :resource="$application" />
    <livewire:project.application.heading :application="$application" />

    <div class="flex flex-col h-full gap-2 md:gap-8 md:flex-row">
        <div class="sub-menu-wrapper">
            <a @class(['sub-menu-item', 'menu-item-active' => $section === 'general']) {{ wireNavigate() }}
                href="{{ route('project.application.backup.show', $parameters) }}">
                <span class="menu-item-label">General</span>
            </a>
            <a @class(['sub-menu-item', 'menu-item-active' => $section === 's3']) {{ wireNavigate() }}
                href="{{ route('project.application.backup.s3', $parameters) }}">
                <span class="menu-item-label">S3</span>
            </a>
            <a @class(['sub-menu-item', 'menu-item-active' => $section === 'retention']) {{ wireNavigate() }}
                href="{{ route('project.application.backup.retention', $parameters) }}">
                <span class="menu-item-label">Retention</span>
            </a>
            <a @class(['sub-menu-item', 'menu-item-active' => $section === 'executions']) {{ wireNavigate() }}
                href="{{ route('project.application.backup.executions', $parameters) }}">
                <span class="menu-item-label">Executions</span>
            </a>
            <a @class(['sub-menu-item', 'menu-item-active' => $section === 'danger']) {{ wireNavigate() }}
                href="{{ route('project.application.backup.danger', $parameters) }}">
                <span class="menu-item-label">Danger Zone</span>
            </a>
        </div>
        <div class="w-full md:flex-grow">
            <livewire:project.shared.storages.volume-backups :storage="$backup->backupable" :resource="$application"
                :section="$section" wire:key="volume-backup-{{ $backup->uuid }}-{{ $section }}" />
        </div>
    </div>
</div>
