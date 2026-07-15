<div>
    @if ($section === 'retention')
        @include('livewire.project.shared.storages.volume-backups.retention')
    @elseif ($section === 's3')
        @include('livewire.project.shared.storages.volume-backups.s3')
    @elseif ($section === 'executions')
        @include('livewire.project.shared.storages.volume-backups.executions')
    @elseif ($section === 'danger')
        @include('livewire.project.shared.storages.volume-backups.danger')
    @else
        @include('livewire.project.shared.storages.volume-backups.general')
    @endif
</div>

@script
<script>
    window.download_volume_backup_file = function(executionId) {
        window.open('/download/volume-backup/' + executionId, '_blank');
    }
</script>
@endscript
