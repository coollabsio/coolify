<x-layout>
    <div class="flex flex-col">
        <div class="flex items-center gap-2">
            <h1>Resources</h1>
            <livewire:project.delete-environment :environment_id="$environment->id" :resource_count="$environment->applications->count()" />
        </div>
        <nav class="flex pt-2 pb-10 text-sm">
            <ol class="inline-flex items-center">
                <li class="inline-flex items-center">
                    <a href="{{ route('project.show', ['project_uuid' => request()->route('project_uuid')]) }}">
                        {{ $project->name }}
                    </a>
                </li>
                <li>
                    <div class="flex items-center">
                        <svg aria-hidden="true" class="w-4 h-4 mx-1 font-bold text-warning" fill="currentColor"
                            viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd"
                                d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                                clip-rule="evenodd"></path>
                        </svg>
                        {{ request()->route('environment_name') }}
                    </div>
                </li>
            </ol>
        </nav>
    </div>
    @if ($environment->applications->count() === 0)
        <p>No resources found.</p>
    @endif
    <div class="grid lg:grid-cols-2 gap-2">
        @foreach ($environment->applications->sortBy('name') as $application)
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
