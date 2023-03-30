<x-layout>
    <h1>Environments</h1>

    @foreach ($project->environments as $environment)
        <div>
            <a href="{{ route('project.resources', [$project->uuid, $environment->name]) }}">
                {{ $environment->name }}
            </a>
        </div>
    @endforeach
</x-layout>
