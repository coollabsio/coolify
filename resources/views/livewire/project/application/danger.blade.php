<div x-data="{ deleteApplication: false }">
    <h2>Danger Zone</h2>
    <x-naked-modal show="deleteApplication" />
    <x-inputs.button isWarning x-on:click.prevent="deleteApplication = true">Delete this application</x-inputs.button>
</div>
