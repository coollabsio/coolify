<div>
    <x-slot:title>
        Project Variables | Coolify
    </x-slot>
    <div class="flex gap-2">
        <h1>Projects</h1>
    </div>
    <div class="subtitle">{{ __('shared.list_projects') }}</div>
    <div class="flex flex-col gap-2">
        @forelse ($projects as $project)
            <a class="coolbox group"
                href="{{ route('shared-variables.project.show', ['project_uuid' => data_get($project, 'uuid')]) }}" {{ wireNavigate() }}>
                <div class="flex flex-col justify-center mx-6 ">
                    <div class="box-title">{{ $project->name }}</div>
                    <div class="box-description ">
                        {{ $project->description }}</div>
                </div>
            </a>
        @empty
            <div>
                <div>{{ __('shared.no_project_found') }}</div>
            </div>
        @endforelse
    </div>
</div>
