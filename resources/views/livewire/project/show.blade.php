<div>
    <x-slot:title>
        {{ data_get_str($project, 'name')->limit(10) }} > Environments | Coolify
    </x-slot>
    <div class="flex items-center gap-2">
        <h1>Environments</h1>
        <x-modal-input buttonTitle="+ Add" title="New Environment">
            <form class="flex flex-col w-full gap-2 rounded" wire:submit='submit'>
                <x-forms.input placeholder="production" id="name" label="Name" required />
                <x-forms.button type="submit">
                    Save
                </x-forms.button>
            </form>
        </x-modal-input>
        <livewire:project.delete-project :disabled="!$project->isEmpty()" :project_id="$project->id" />
    </div>
    <div class="text-xs truncate subtitle lg:text-sm">{{ $project->name }}.</div>
    <div class="grid gap-2 lg:grid-cols-2">
        @forelse ($project->environments->sortBy('created_at') as $environment)
            <div class="gap-2 border border-transparent box group">
                <div class="flex flex-1 mx-6">
                    <a class="flex flex-col justify-center flex-1"
                        wire:navigate
                        href="{{ route('project.resource.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}">
                        <div class="font-bold dark:text-white"> {{ $environment->name }}</div>
                        <div class="description">
                            {{ $environment->description }}</div>
                    </a>
                    <div class="flex items-center justify-center gap-2 text-xs">
                        <a class="font-bold hover:underline"
                            wire:navigate
                            href="{{ route('project.environment.edit', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]) }}">
                            Settings
                        </a>
                    </div>
                </div>
            </div>
        @empty
            <p>No environments found.</p>
        @endforelse
    </div>
</div>
