<div>
    <h1>Backups</h1>
    <livewire:project.database.heading :database="$database" />
    <div class="pt-6">
        <livewire:project.database.backup-edit :backup="$backup" :s3s="$s3s" :status="data_get($database, 'status')" />
        <div class="flex items-center gap-2">
            <h3 class="py-4">Executions</h3>
            <x-forms.button wire:click='cleanupFailed'>Cleanup Failed Backups</x-forms.button>
        </div>
        <livewire:project.database.backup-executions :backup="$backup" :executions="$executions" />
    </div>
</div>
