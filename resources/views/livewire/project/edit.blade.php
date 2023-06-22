<div>
    <h1>{{ data_get($project, 'name') }}</h1>
    <div class="pt-2 pb-10">Edit project details</div>
    <form wire:submit.prevent='submit' class="flex items-end gap-2 ">
        <x-forms.input label="Name" id="project.name" />
        <x-forms.input label="Description" id="project.description" />
        <x-forms.button type="submit">Save</x-forms.button>
    </form>
</div>
