<div>
    <x-slot:title>
        Projects | Coolify
    </x-slot>
    <div class="flex gap-2">
        <h1>Projects</h1>
        @can('createAnyResource')
            <x-modal-input buttonTitle="+ Add" title="New Project">
                <livewire:project.add-empty />
            </x-modal-input>
        @endcan
    </div>
    <div class="subtitle">All your projects are here.</div>
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-2 -mt-1">
        @foreach ($projects as $project)
            <div class="relative gap-2 cursor-pointer coolbox group">
                <a href="{{ $project->navigateTo() }}" {{ wireNavigate() }} class="absolute inset-0"></a>
                <div class="flex flex-1 mx-6">
                    <div class="flex flex-col justify-center flex-1">
                        <div class="box-title">{{ $project->name }}</div>
                        <div class="box-description">
                            {{ $project->description }}
                        </div>
                    </div>
                    <div class="relative z-10 flex items-center justify-center gap-4 text-xs font-bold">
                        @if ($project->environments->first())
                            @can('createAnyResource')
                                <a class="hover:underline" {{ wireNavigate() }}
                                    href="{{ route('project.resource.create', [
                                        'project_uuid' => $project->uuid,
                                        'environment_uuid' => $project->environments->first()->uuid,
                                    ]) }}">
                                    + Add Resource
                                </a>
                            @endcan
                        @endif
                        @can('update', $project)
                            <a class="hover:underline" {{ wireNavigate() }}
                                href="{{ route('project.edit', ['project_uuid' => $project->uuid]) }}">
                                Settings
                            </a>
                        @endcan
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</div>
