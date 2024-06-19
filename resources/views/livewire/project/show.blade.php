<div>
    <x-slot:title>
        {{ data_get_str($project, 'name')->limit(10) }} > Environments | Coolify
    </x-slot>
    <div class="flex items-center gap-2">
        <h1>Environments</h1>
        <x-modal-input buttonTitle="+ Add" title="New Environment">
            <livewire:project.add-environment :project="$project" />
        </x-modal-input>
        <livewire:project.delete-project :disabled="$project->resource_count() > 0" :project_id="$project->id" />
    </div>
    <div class="text-xs truncate subtitle lg:text-sm">{{ $project->name }}.</div>
    <div class="grid gap-2 lg:grid-cols-2">
        @forelse ($project->environments as $environment)
            <div class="gap-2 border border-transparent cursor-pointer box group" x-data
                x-on:click="goto('{{ $project->uuid }}','{{ $environment->name }}')">
                <div class="flex flex-1 mx-6">
                    <a class="flex flex-col justify-center flex-1"
                        href="{{ route('project.resource.index', [$project->uuid, $environment->name]) }}">
                        <div class="font-bold dark:text-white"> {{ $environment->name }}</div>
                        <div class="description">
                            {{ $environment->description }}</div>
                    </a>
                    <div class="flex items-center justify-center gap-2 text-xs">
                        <a class="font-bold hover:underline"
                            href="{{ route('project.environment.edit', ['project_uuid' => data_get($project, 'uuid'), 'environment_name' => $environment->name]) }}">
                            Settings
                        </a>
                    </div>
                </div>
                {{-- <div class="flex items-center justify-center gap-2 pt-4 pb-2 mr-4 text-xs lg:py-0 lg:justify-normal">
                    <a class="mx-4 font-bold hover:underline"
                        href="{{ route('project.environment.edit', ['project_uuid' => data_get($project, 'uuid'), 'environment_name' => $environment->name]) }}">
                        Settings
                    </a>
                </div> --}}
            </div>
        @empty
            <p>No environments found.</p>
        @endforelse
        <script>
            function goto(projectUuid, environmentName) {
                window.location.href = '/project/' + projectUuid + '/' + environmentName;
            }
        </script>
    </div>
</div>
