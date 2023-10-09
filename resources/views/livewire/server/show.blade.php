<div>
    <x-modal modalId="installDocker">
        <x-slot:modalBody>
            <livewire:activity-monitor header="Docker Installation Logs" />
        </x-slot:modalBody>
        <x-slot:modalSubmit>
            <x-forms.button onclick="installDocker.close()" type="submit">
                Close
            </x-forms.button>
        </x-slot:modalSubmit>
    </x-modal>
    <x-server.navbar :server="$server" :parameters="$parameters" />
    <livewire:server.form :server="$server" />
</div>
