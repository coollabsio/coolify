<x-layout>
    <div class="flex flex-col">
        <div class="flex items-center gap-2">
            <h1 class="pb-0">Resources</h1>
            <livewire:project.delete-environment :environment_id="$environment->id" :resource_count="$environment->applications->count()" />
        </div>
        <div class="pb-10 text-sm breadcrumbs">
            <ul>
                <li>
                    <a href="{{ route('project.show', ['project_uuid' => request()->route('project_uuid')]) }}">
                        {{ $project->name }}
                    </a>
                </li>
                <li>
                    {{ request()->route('environment_name') }}
                </li>
            </ul>
        </div>
    </div>
    @if ($environment->applications->count() === 0)
        <p>No resources found.</p>
    @endif
    <div class="flex flex-col gap-2">
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
