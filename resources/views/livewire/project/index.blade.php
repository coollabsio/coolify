<div>
    <x-slot:title>
        Projects | Coolify
    </x-slot>
    <div class="flex gap-2">
        <h1>Projects</h1>
        <x-modal-input buttonTitle="+ Add" title="New Project">
            <livewire:project.add-empty />
        </x-modal-input>
    </div>
    <div class="subtitle">All your projects are here.</div>
    <div class="grid gap-2 lg:grid-cols-2">
        @forelse ($projects as $project)
            <div class="box group" onclick="gotoProject('{{ $project->uuid }}', '{{ $project->default_environment() }}')">
                <div class="flex flex-col justify-center flex-1 mx-6">
                    <div class="box-title">{{ $project->name }}</div>
                    <div class="box-description ">
                        {{ $project->description }}</div>
                </div>
                <div class="flex items-center justify-center gap-2 pt-4 pb-2 mr-4 text-xs lg:py-0 lg:justify-normal">
                    <a class="mx-4 font-bold hover:underline"
                        href="{{ route('project.edit', ['project_uuid' => data_get($project, 'uuid')]) }}">
                        Settings
                    </a>
                </div>
            </div>
        @empty
            <div>
                <div>No project found.</div>
            </div>
        @endforelse
    </div>

    <script>
    function gotoProject(uuid, environment) {
        if (environment) {
            window.location.href = '/project/' + uuid + '/' + environment;
        } else {
            window.location.href = '/project/' + uuid;
        }
    }
</script>
</div>
