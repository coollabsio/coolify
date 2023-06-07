<div x-data="{ deleteProject: false }">
    <x-naked-modal show="deleteProject" message='Are you sure you would like to delete this project?' />
    @if ($resource_count > 0)
        <x-forms.button disabled="First delete all resources.">
            Delete Project
        </x-forms.button>
    @else
        <x-forms.button x-on:click.prevent="deleteProject = true">
            Delete Project
        </x-forms.button>
    @endif
</div>
