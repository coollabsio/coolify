<x-layout>
    <div class="flex items-center gap-2">
        <h1>Resources</h1>
        <livewire:project.delete :project_id="$project->id" :resource_count="$project->applications->count()" />
    </div>
    @if ($environment->applications->count() === 0)
        <p>No resources yet.</p>
    @endif
    <div class="flex">
        @foreach ($environment->applications as $application)
            <a class="box"
                href="{{ route('project.application.configuration', [$project->uuid, $environment->name, $application->uuid]) }}">
                {{ $application->name }}
            </a>
        @endforeach
        {{-- @foreach ($environment->databases as $database)
            <p>
                <a href="{{ route('project.database', [$project->uuid, $environment->name, $database->uuid]) }}">
                    {{ $database->name }}
                </a>
            </p>
        @endforeach
        @foreach ($environment->services as $service)
            <p>
                <a href="{{ route('project.service', [$project->uuid, $environment->name, $service->uuid]) }}">
                    {{ $service->name }}
                </a>
            </p>
        @endforeach --}}
    </div>
</x-layout>
