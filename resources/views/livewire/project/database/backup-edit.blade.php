<div class="flex flex-col gap-6">
    @if ($section === 'danger' && $backup->database_id !== 0)
        @include('livewire.project.database.backup-edit.danger')
    @else
        @include('livewire.project.database.backup-edit.general')
    @endif
</div>
