<div>
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
    <div class="flex gap-2">
        <h2>Shared Variables</h2>
        <x-modal-input buttonTitle="+ Add" title="New Shared Variable">
            <livewire:project.shared.environment-variable.add />
        </x-modal-input>
    </div>
    <div class="pb-4 lg:flex lg:gap-1">
        <div>You can use these variables anywhere with</div>
        <div class=" dark:text-warning text-coollabs">@{{ project.VARIABLENAME }} </div>
        <x-helper
            helper="More info <a class='underline dark:text-white' href='https://coolify.io/docs/environment-variables#shared-variables' target='_blank'>here</a>."></x-helper>
    </div>
    <div class="flex flex-col gap-2">
        @forelse ($project->environment_variables->sort()->sortBy('real_value') as $env)
            <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}"
                :env="$env" type="project" />
        @empty
            <div>No environment variables found.</div>
        @endforelse
    </div>
</div>
