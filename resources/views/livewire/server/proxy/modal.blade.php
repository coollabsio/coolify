<div>
    <x-modal submitWireAction="proxyStatusUpdated" modalId="startProxy">
        <x-slot:modalBody>
            <livewire:activity-monitor header="Proxy Startup Logs" />
        </x-slot:modalBody>
        <x-slot:modalSubmit>
            <x-forms.button onclick="startProxy.close()" type="submit">
                Close
            </x-forms.button>
        </x-slot:modalSubmit>
    </x-modal>
</div>
