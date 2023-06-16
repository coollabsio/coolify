<div x-data="{ deleteProject: false }">
    <x-naked-modal show="deleteProject" message='Are you sure you would like to delete this project?' />
    <x-forms.button x-on:click.prevent="deleteProject = true">
        Delete Project
    </x-forms.button>
</div>
