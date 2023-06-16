<div x-data="{ deleteEnvironment: false }">
    <x-naked-modal show="deleteEnvironment" message='Are you sure you would like to delete this environment?' />
    <x-forms.button x-on:click.prevent="deleteEnvironment = true">
        Delete Environment
    </x-forms.button>
</div>
