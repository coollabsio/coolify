<div x-data="{ deleteEnvironment: false }">
    <x-naked-modal show="deleteEnvironment" message='Are you sure you would like to delete this environment?' />
    @if ($resource_count > 0)
        <x-forms.button tooltip="First delete all resources." disabled>
            Delete
        </x-forms.button>
    @else
        <x-forms.button x-on:click.prevent="deleteEnvironment = true">
            Delete
        </x-forms.button>
    @endif
</div>
