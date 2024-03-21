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
        <div class="flex gap-2 ">
            <h2 class="pb-4">Scheduled Backups</h2>
            <x-slide-over>
                <x-slot:title>New Scheduled Backup</x-slot:title>
                <x-slot:content>
                    <livewire:project.database.create-scheduled-backup :database="$database" :s3s="$s3s" />
                </x-slot:content>
                <button @click="slideOverOpen=true" class="button">+
                    Add</button>
            </x-slide-over>
        </div>
        <livewire:project.database.scheduled-backups :database="$database" />
    </div>
</div>
