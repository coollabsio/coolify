<div x-data="{ deleteProject: false }">
    <x-naked-modal show="deleteProject" title="Delete Project"
        message='This project will be deleted. It is not reversible. <br>Please think again.' />
    <x-forms.button isError x-on:click.prevent="deleteProject = true">
        Delete Project
    </x-forms.button>
</div>
