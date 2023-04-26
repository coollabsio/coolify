<x-layout>
    <h1>Resources <a href="{{ route('project.resources.new', Route::current()->parameters()) }}"><button>New</button></a>
    </h1>
    <div>
        @foreach ($environment->applications as $application)
            <p>
                <a
                    href="{{ route('project.application.configuration', [$project->uuid, $environment->name, $application->uuid]) }}">
                    {{ $application->name }}
                </a>
            </p>
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
