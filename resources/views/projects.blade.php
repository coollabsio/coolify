<x-layout>
    <h1>Projects</h1>
    <div class="pt-2 pb-10 text-sm">All Projects</div>
    <div class="grid gap-2 lg:grid-cols-2">
        @forelse ($projects as $project)
            <a href="{{ route('project.show', ['project_uuid' => data_get($project, 'uuid')]) }}"
                class="box">{{ $project->name }}</a>
        @empty
            <div>
                <div>No project found.</div>
                <x-use-magic-bar />
            </div>
        @endforelse
    </div>
</x-layout>
