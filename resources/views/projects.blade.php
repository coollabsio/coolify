<x-layout>
    <h1>Projects</h1>
    @forelse ($projects as $project)
        <a href="{{ route('project.show', ['project_uuid' => data_get($project, 'uuid')]) }}"
            class="box">{{ $project->name }}</a>
    @empty
        No project found.
    @endforelse
</x-layout>
