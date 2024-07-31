<div>
    <x-slot:title>
        {{ data_get_str($project, 'name')->limit(10) }} > Edit | Coolify
    </x-slot>
    <form wire:submit='submit' class="flex flex-col pb-10">
        <div class="flex gap-2">
            <h1>Project: {{ data_get($project, 'name') }}</h1>
            <div class="flex items-end gap-2">
                <x-forms.button type="submit">Save</x-forms.button>
                <livewire:project.delete-project :disabled="$project->resource_count() > 0" :project_id="$project->id" />
            </div>
        </div>
        <div class="pt-2 pb-10">Edit project details here.</div>

        <div class="flex gap-2">
            <x-forms.input label="Name" id="project.name" />
            <x-forms.input label="Description" id="project.description" />
        </div>
    </form>
</div>
