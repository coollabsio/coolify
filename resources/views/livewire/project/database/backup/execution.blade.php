<div>
    <h1>Backups</h1>
    <livewire:project.database.heading :database="$database" />
    <x-modal modalId="startDatabase">
        <x-slot:modalBody>
            <livewire:activity-monitor header="Database Startup Logs" />
        </x-slot:modalBody>
        <x-slot:modalSubmit>
            <x-forms.button onclick="startDatabase.close()" type="submit">
                Close
            </x-forms.button>
        </x-slot:modalSubmit>
    </x-modal>
    <div class="pt-6">
        <livewire:project.database.backup-edit :backup="$backup" :s3s="$s3s" :status="data_get($database, 'status')" />
        <div class="flex items-center gap-2">
            <h3 class="py-4">Executions</h3>
            <x-forms.button wire:click='cleanupFailed'>Cleanup Failed Backups</x-forms.button>
        </div>
        <livewire:project.database.backup-executions :backup="$backup" :executions="$executions" />
    </div>
</div>
