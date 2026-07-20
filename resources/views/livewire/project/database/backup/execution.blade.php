<div>
    <x-slot:title>
        {{ data_get_str($database, 'name')->limit(10) }} > Backup | Coolify
    </x-slot>
    <h1>Backups</h1>
    <livewire:project.shared.configuration-checker :resource="$database" />
    <livewire:project.database.heading :database="$database" />

    <div class="flex flex-col h-full gap-2 md:gap-8 md:flex-row">
        <div class="sub-menu-wrapper">
            <a @class(['sub-menu-item', 'menu-item-active' => $section === 'general']) {{ wireNavigate() }}
                href="{{ route('project.database.backup.execution', $parameters) }}">
                <span class="menu-item-label">General</span>
            </a>
            <a @class(['sub-menu-item', 'menu-item-active' => $section === 's3']) {{ wireNavigate() }}
                href="{{ route('project.database.backup.s3', $parameters) }}">
                <span class="menu-item-label">S3</span>
            </a>
            <a @class(['sub-menu-item', 'menu-item-active' => $section === 'retention']) {{ wireNavigate() }}
                href="{{ route('project.database.backup.retention', $parameters) }}">
                <span class="menu-item-label">Retention</span>
            </a>
            <a @class(['sub-menu-item', 'menu-item-active' => $section === 'executions']) {{ wireNavigate() }}
                href="{{ route('project.database.backup.executions', $parameters) }}">
                <span class="menu-item-label">Executions</span>
            </a>
            <a @class(['sub-menu-item', 'menu-item-active' => $section === 'danger']) {{ wireNavigate() }}
                href="{{ route('project.database.backup.danger', $parameters) }}">
                <span class="menu-item-label">Danger Zone</span>
            </a>
        </div>
        <div class="w-full md:flex-grow">
            @if ($section === 'executions')
                <livewire:project.database.backup-executions :backup="$backup" />
            @else
                <livewire:project.database.backup-edit :backup="$backup" :available-s3-storages="$s3s"
                    :status="data_get($database, 'status')" :section="$section"
                    wire:key="database-backup-{{ $backup->uuid }}-{{ $section }}" />
            @endif
        </div>
    </div>
</div>
