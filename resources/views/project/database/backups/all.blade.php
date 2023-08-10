<x-layout>
    <h1>Backups</h1>
    <livewire:project.database.heading :database="$database"/>
    <x-modal modalId="startDatabase">
        <x-slot:modalBody>
            <livewire:activity-monitor header="Startup Logs"/>
        </x-slot:modalBody>
        <x-slot:modalSubmit>
            <x-forms.button onclick="startDatabase.close()" type="submit">
                Close
            </x-forms.button>
        </x-slot:modalSubmit>
    </x-modal>

    <livewire:project.database.create-scheduled-backup :database="$database"/>
    <div class="pt-6">
        <div class="flex gap-2 ">
            <h2 class="pb-4">Scheduled Backups</h2>
            <x-forms.button onclick="createScheduledBackup.showModal()">+ Add</x-forms.button>
        </div>
        <livewire:project.database.scheduled-backups :database="$database"/>
    </div>
</x-layout>
