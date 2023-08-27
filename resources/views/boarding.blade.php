<x-layout-simple>
    <livewire:boarding />
    <x-modal modalId="installDocker">
        <x-slot:modalBody>
            <livewire:activity-monitor header="Installing Docker Logs" />
        </x-slot:modalBody>
        <x-slot:modalSubmit>
            <x-forms.button onclick="installDocker.close()" type="submit">
                Close
            </x-forms.button>
        </x-slot:modalSubmit>
    </x-modal>
</x-layout-simple>
