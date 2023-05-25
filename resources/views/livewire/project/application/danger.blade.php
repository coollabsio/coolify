<div x-data="{ deleteApplication: false }">
    <h2>Danger Zone</h2>
    <x-naked-modal show="deleteApplication" />
    <x-forms.button x-on:click.prevent="deleteApplication = true">Delete this application</x-forms.button>
</div>
