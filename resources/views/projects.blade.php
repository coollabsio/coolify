<x-layout>
    <h1>Projects</h1>
    <nav class="flex pt-2 pb-10 text-sm">
        <ol class="inline-flex items-center">
            <li class="inline-flex items-center">
                All Projects
            </li>

        </ol>
    </nav>
    <div class="grid grid-cols-2 gap-2">
        @forelse ($projects as $project)
            <a href="{{ route('project.show', ['project_uuid' => data_get($project, 'uuid')]) }}"
                class="box">{{ $project->name }}</a>
        @empty
            <div>
                No project found.
                <x-use-magic-bar />
            </div>
        @endforelse
    </div>
</x-layout>
