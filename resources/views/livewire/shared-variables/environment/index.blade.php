<div>
    <x-slot:title>
        Environment Variables | Coolify
    </x-slot>
    <div class="flex gap-2">
        <h1>Environments</h1>
    </div>
    <div class="subtitle">List of your environments by projects.</div>
    <div class="flex flex-col gap-2">
        @forelse ($projects as $project)
            <h2>{{ data_get($project, 'name') }}</h2>
            <div class="pt-0 pb-3">{{ data_get($project, 'description') }}</div>
            @forelse ($project->environments as $environment)
                <a class="box group"
                    href="{{ route('shared-variables.environment.show', ['project_uuid' => $project->uuid, 'environment_name' => $environment->name]) }}">
                    <div class="flex flex-col justify-center flex-1 mx-6 ">
                        <div class="box-title"> {{ $environment->name }}</div>
                        <div class="box-description">
                            {{ $environment->description }}</div>
                    </div>
                </a>
            @empty
                <p>No environments found.</p>
            @endforelse
        @empty
            <div>
                <div>No project found.</div>
            </div>
        @endforelse
    </div>
</div>
