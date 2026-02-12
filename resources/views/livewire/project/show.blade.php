<div>
    <x-slot:title>
        {{ data_get_str($project, 'name')->limit(10) }} > Environments | Coolify
    </x-slot>
    <div class="form-section-title mb-6">
        <h1>Environments</h1>
        <div class="flex items-center gap-2">
            @can('update', $project)
                <x-modal-input buttonTitle="+ Add" title="New Environment">
                    <form class="flex flex-col w-full gap-8 rounded-sm" wire:submit='submit'>
                        <x-forms.input placeholder="production" id="name" label="Name" required />
                        <x-forms.button type="submit">
                            Save
                        </x-forms.button>
                    </form>
                </x-modal-input>
            @endcan
            @can('delete', $project)
                <livewire:project.delete-project :disabled="!$project->isEmpty()" :project_id="$project->id" />
            @endcan
        </div>
    </div>
    <p class="text-sm text-neutral-500 dark:text-neutral-400 -mt-4 mb-4">{{ $project->name }}.</p>
    <div class="grid gap-2 lg:grid-cols-2">
        @forelse ($project->environments->sortBy('created_at') as $environment)
            <div class="gap-2 coolbox group">
                <div class="flex flex-1 mx-6">
                    <a class="flex flex-col justify-center flex-1" {{ wireNavigate() }}
                        href="{{ route('project.resource.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}">
                        <div class="font-bold dark:text-white"> {{ $environment->name }}</div>
                        <div class="description">
                            {{ $environment->description }}</div>
                    </a>
                    @can('update', $project)
                        <div class="flex items-center justify-center gap-2 text-xs">
                            <a class="font-bold hover:underline" {{ wireNavigate() }}
                                href="{{ route('project.environment.edit', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}">
                                Settings
                            </a>
                        </div>
                    @endcan
                </div>
            </div>
        @empty
            <p class="empty-state">No environments found.</p>
        @endforelse
    </div>
</div>
