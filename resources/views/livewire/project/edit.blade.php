<div>
    <x-slot:title>
        {{ data_get_str($project, 'name')->limit(10) }} > Edit | Coolify
        </x-slot>
        <form wire:submit='submit' class="flex flex-col pb-10">
            <div class="form-section-title">
                <h1>{{ data_get_str($project, 'name')->limit(15) }}</h1>
                <div class="flex items-center gap-2">
                    <x-forms.button type="submit">Save</x-forms.button>
                    <livewire:project.delete-project :disabled="!$project->isEmpty()" :project_id="$project->id" />
                </div>
            </div>
            <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400 pb-10">Edit project details here.</p>
            <div class="flex gap-2">
                <x-forms.input label="Name" id="name" />
                <x-forms.input label="Description" id="description" />
            </div>
        </form>
</div>