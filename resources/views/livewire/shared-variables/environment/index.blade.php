<div>
    <x-slot:title>
        Environment Variables | Coolify
    </x-slot>
    <h1>Environments</h1>
    <p class="text-sm text-neutral-500 dark:text-neutral-400 mb-4">List of your environments by projects.</p>
    <div class="flex flex-col gap-10">
        @forelse ($projects as $project)
            <h2>Project: {{ data_get($project, 'name') }}</h2>
            <div class="pt-0 pb-3">{{ data_get($project, 'description') }}</div>
            @forelse ($project->environments as $environment)
                <a class="coolbox group"
                    href="{{ route('shared-variables.environment.show', [
                        'project_uuid' => $project->uuid,
                        'environment_uuid' => $environment->uuid,
                    ]) }}" {{ wireNavigate() }}>
                    <div class="flex flex-col justify-center flex-1 mx-6 ">
                        <div class="box-title"> {{ $environment->name }}</div>
                        <div class="box-description">
                            {{ $environment->description }}</div>
                    </div>
                </a>
            @empty
                <p class="empty-state">No environments found.</p>
            @endforelse
        @empty
            <div class="empty-state">No project found.</div>
        @endforelse
    </div>
</div>
