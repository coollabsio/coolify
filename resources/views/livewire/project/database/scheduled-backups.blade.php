<div>
    <div class="flex flex-col gap-2">
        @forelse($database->scheduledBackups as $backup)
            @if ($type == 'database')
                <a class="box"
                    wire:navigate
                    href="{{ route('project.database.backup.execution', [...$parameters, 'backup_uuid' => $backup->uuid]) }}">
                    <div class="flex flex-col">
                        <div>Frequency: {{ $backup->frequency }}
                            ({{ data_get($backup->server(), 'settings.server_timezone', 'Instance timezone') }})
                        </div>
                        <div>Last backup: {{ data_get($backup->latest_log, 'status', 'No backup yet') }}</div>
                    </div>
                </a>
            @else
                <div class="box" wire:navigate wire:click="setSelectedBackup('{{ data_get($backup, 'id') }}')">
                    <div @class([
                        'border-coollabs' =>
                            data_get($backup, 'id') === data_get($selectedBackup, 'id'),
                        'flex flex-col border-l-2 border-transparent',
                    ])>
                        <div>Frequency: {{ $backup->frequency }}
                            ({{ data_get($backup->server(), 'settings.server_timezone', 'Instance timezone') }})
                        </div>
                        <div>Last backup: {{ data_get($backup->latest_log, 'status', 'No backup yet') }}</div>
                    </div>
                </div>
            @endif
        @empty
            <div>No scheduled backups configured.</div>
        @endforelse
    </div>
    @if ($type === 'service-database' && $selectedBackup)
        <div class="pt-10">
            <livewire:project.database.backup-edit wire:key="{{ $selectedBackup->id }}" :backup="$selectedBackup"
                :s3s="$s3s" :status="data_get($database, 'status')" />
            <livewire:project.database.backup-executions wire:key="{{ $selectedBackup->uuid }}" :backup="$selectedBackup"
                :database="$database" />
        </div>
    @endif
</div>
