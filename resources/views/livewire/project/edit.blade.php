<div>
    <x-slot:title>
        {{ data_get_str($project, 'name')->limit(10) }} > Edit | Coolify
    </x-slot>

    <div class="flex flex-col pb-10">
        <div class="flex gap-2">
            <h1>{{ data_get_str($project, 'name')->limit(15) }}</h1>
            <div class="flex items-end gap-2">
                <x-forms.button type="submit" form="edit-form">Save</x-forms.button>
                <livewire:project.delete-project :disabled="!$project->isEmpty()" :project_id="$project->id" />
            </div>
        </div>
        <div class="pt-2 pb-10">Edit project details here.</div>

        {{-- Navigation Tabs --}}
        <div class="flex gap-2 mb-4 border-b dark:border-gray-700">
            <a href="{{ route('project.edit', ['project_uuid' => $project->uuid]) }}" {{ wireNavigate() }}
                class="px-4 py-2 text-sm font-medium border-b-2 border-primary text-primary">
                General
            </a>
            @can('manageMembers', $project)
                <a href="{{ route('project.members', ['project_uuid' => $project->uuid]) }}" {{ wireNavigate() }}
                    class="px-4 py-2 text-sm font-medium border-b-2 border-transparent hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300">
                    Members
                </a>
            @endcan
        </div>

        <form id="edit-form" wire:submit='submit' class="flex flex-col gap-2">
            <div class="flex gap-2">
                <x-forms.input label="Name" id="name" />
                <x-forms.input label="Description" id="description" />
            </div>
        </form>
    </div>
</div>