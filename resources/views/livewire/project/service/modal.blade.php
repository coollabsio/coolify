<div>
    <x-modal noSubmit modalId="startService">
        <x-slot:modalBody>
            <livewire:activity-monitor header="Service Startup Logs" />
        </x-slot:modalBody>
        <x-slot:modalSubmit>
            <x-forms.button onclick="startService.close()" type="submit">
                Close
            </x-forms.button>
        </x-slot:modalSubmit>
    </x-modal>
</div>
