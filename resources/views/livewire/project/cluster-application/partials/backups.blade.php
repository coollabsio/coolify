@php
    $backupUuid = request()->route('backup_uuid');
    // A database has dump backups (decision 0010) next to the volume backups of decision 0009.
    $showsDatabaseBackup = $workload->isDatabase() && \App\Livewire\Project\ClusterApplication\DatabaseBackups::ownsSchedule($workload, $backupUuid);
@endphp
<div class="flex min-w-0 flex-col gap-10">
    @if ($workload->isDatabase() && ($backupUuid === null || $showsDatabaseBackup))
        <livewire:project.cluster-application.database-backups :workload="$workload" :backup-uuid="$backupUuid"
            wire:key="cluster-application-database-backups-{{ $workload->uuid }}-{{ $backupUuid ?? 'index' }}" />
    @endif
    @unless ($showsDatabaseBackup)
        <livewire:project.cluster-application.volume-backups :workload="$workload" :backup-uuid="$backupUuid"
            wire:key="cluster-application-volume-backups-{{ $workload->uuid }}-{{ $backupUuid ?? 'index' }}" />
    @endunless
</div>
