<div>
    <x-modal noSubmit modalId="installDocker">
        <x-slot:modalBody>
            <livewire:activity-monitor header="Installation Logs" />
        </x-slot:modalBody>
        <x-slot:modalSubmit>
            <x-forms.button onclick="installDocker.close()" type="submit">
                Close
            </x-forms.button>
        </x-slot:modalSubmit>
    </x-modal>
    <x-server.navbar :server="$server" />
    <livewire:server.form :server="$server" />
</div>
