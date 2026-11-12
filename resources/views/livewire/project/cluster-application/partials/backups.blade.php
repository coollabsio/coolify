<livewire:project.cluster-application.volume-backups :workload="$workload" :backup-uuid="request()->route('backup_uuid')"
    wire:key="cluster-application-volume-backups-{{ $workload->uuid }}-{{ request()->route('backup_uuid') ?? 'index' }}" />
