<div>
    <form wire:submit.prevent='submit' class="flex flex-col gap-2 ">
        <div class="flex items-end gap-2">
            <h1>Project: {{ data_get($project, 'name') }}</h1>
            <x-forms.button type="submit">Save</x-forms.button>
            @if ($project->applications->count() === 0)
                <livewire:project.delete-project :project_id="$project->id" />
            @endif
        </div>
        <div class="pb-10">Edit project details here.</div>
        <div class="flex gap-2">
            <x-forms.input label="Name" id="project.name" />
            <x-forms.input label="Description" id="project.description" />
        </div>
    </form>
</div>
