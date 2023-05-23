<div x-data="{ deleteEnvironment: false }">
    <x-naked-modal show="deleteEnvironment" message='Are you sure you would like to delete this environment?' />
    @if ($resource_count > 0)
        <x-inputs.button tooltip="First delete all resources." disabled>
            Delete
        </x-inputs.button>
    @else
        <x-inputs.button x-on:click.prevent="deleteEnvironment = true">
            Delete
        </x-inputs.button>
    @endif
</div>
