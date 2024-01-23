<div>
    <h1>Project: {{ data_get($project, 'name') }}</h1>
    <div class="pb-10">Edit project details here.</div>
    <form wire:submit='submit' class="flex flex-col gap-2 pb-10">
        <div class="flex items-end gap-2">
            <h2>General</h2>
            <x-forms.button type="submit">Save</x-forms.button>
        </div>
        <div class="flex gap-2">
            <x-forms.input label="Name" id="project.name" />
            <x-forms.input label="Description" id="project.description" />
        </div>
    </form>
    <div class="flex gap-2">
        <h2>Shared Variables</h2>
        <x-forms.button class="btn" onclick="newVariable.showModal()">+ Add</x-forms.button>
        <livewire:project.shared.environment-variable.add />
    </div>
    <div class="pb-4">You can use this anywhere.</div>
    <div class="flex flex-col gap-2">
        @forelse ($project->environment_variables->sort()->sortBy('real_value') as $env)
            <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}"
                :env="$env" type="project" />
        @empty
            <div class="text-neutral-500">No environment variables found.</div>
        @endforelse
    </div>
</div>
