<div>
    <div class="flex flex-col gap-2">
        @if ($database->is_migrated && blank($database->custom_type))
            <div>
                <div>Select the type of
                    database to enable automated backups.</div>
                <div class="pb-4"> If your database is not listed, automated backups are not supported.</div>
                <form wire:submit="setCustomType" class="flex gap-2 items-end">
                    <div class="w-96">
                        <x-forms.select label="Type" id="custom_type">
                            <option selected value="mysql">MySQL</option>
                            <option value="mariadb">MariaDB</option>
                            <option value="postgresql">PostgreSQL</option>
                            <option value="mongodb">MongoDB</option>
                        </x-forms.select>
                    </div>
                    <x-forms.button type="submit">Set</x-forms.button>
                </form>
            </div>
        @else
            @forelse($database->scheduledBackups as $backup)
                @if ($type == 'database')
                    <a class="box"
                        href="{{ route('project.database.backup.execution', [...$parameters, 'backup_uuid' => $backup->uuid]) }}">
                        <div class="flex flex-col">
                            <div>Frequency: {{ $backup->frequency }}
                                ({{ data_get($backup->server(), 'settings.server_timezone', 'Instance timezone') }})
                            </div>
                            <div>Last backup: {{ data_get($backup->latest_log, 'status', 'No backup yet') }}</div>
                        </div>
                    </a>
                @else
                    <div class="box" wire:click="setSelectedBackup('{{ data_get($backup, 'id') }}')">
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
        @endif
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
