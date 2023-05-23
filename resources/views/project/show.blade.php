<x-layout>
    <h1 class="pb-0">Environments</h1>
    <div class="pb-10 text-sm breadcrumbs">
        <ul>
            <li>{{ $project->name }} </li>
        </ul>
    </div>
    <div class="flex flex-col gap-2">
        @foreach ($project->environments as $environment)
            <a class="box" href="{{ route('project.resources', [$project->uuid, $environment->name]) }}">
                {{ $environment->name }}
            </a>
        @endforeach
    </div>
</x-layout>
