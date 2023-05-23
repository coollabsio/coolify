<div x-data="{ deleteProject: false }">
    <x-naked-modal show="deleteProject" message='Are you sure you would like to delete this project?' />
    @if ($resource_count > 0)
        <x-inputs.button disabled="First delete all resources.">
            Delete
        </x-inputs.button>
    @else
        <x-inputs.button x-on:click.prevent="deleteProject = true">
            Delete
        </x-inputs.button>
    @endif
</div>
