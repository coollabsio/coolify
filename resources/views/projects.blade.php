<x-layout>
    <h1 class="py-0">Projects</h1>
    <div class="pb-10 text-sm breadcrumbs">
        <ul>
            <li>
                All Projects
            </li>
        </ul>
    </div>
    <div class="flex flex-col gap-2">
        @forelse ($projects as $project)
            <a href="{{ route('project.show', ['project_uuid' => data_get($project, 'uuid')]) }}"
                class="box">{{ $project->name }}</a>
        @empty
            <div x-data>
                No project found. Use the <button x-on:click="$dispatch('slash')" class='text-white underline'>magic
                    bar</button> to create a new
                project.
            </div>
        @endforelse
    </div>
</x-layout>
