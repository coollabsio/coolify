<x-layout>
    <h1>Projects</h1>
    <div class="pb-10 text-sm breadcrumbs">
        <ul>
            <li>
                All Projects
            </li>
        </ul>
    </div>
    <div class="grid grid-cols-2 gap-2">
        @forelse ($projects as $project)
            <a href="{{ route('project.show', ['project_uuid' => data_get($project, 'uuid')]) }}"
                class="box">{{ $project->name }}</a>
        @empty
            <div>
                No project found.
                <x-use-magic-bar />
            </div>
            <div>
                If you do not have a project yet, just create a resource (application, database, etc.) first, it will
                create a new project for you automatically.
            </div>
        @endforelse
    </div>
</x-layout>
