<div>
    @if ($backup->database_id === 0)
        @include('livewire.project.database.backup-edit.general')
        @include('livewire.project.database.backup-edit.s3')
        @include('livewire.project.database.backup-edit.retention')
    @elseif ($section === 'retention')
        @include('livewire.project.database.backup-edit.retention')
    @elseif ($section === 's3')
        @include('livewire.project.database.backup-edit.s3')
    @elseif ($section === 'danger')
        @include('livewire.project.database.backup-edit.danger')
    @else
        @include('livewire.project.database.backup-edit.general')
    @endif
</div>
