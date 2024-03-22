<div>
    <div class="flex gap-2">
        <h1>Projects</h1>
        <x-slide-over>
            <x-slot:title>New Project</x-slot:title>
            <x-slot:content>
                <livewire:project.add-empty />
            </x-slot:content>
            <button @click="slideOverOpen=true" class="button">+
                Add</button>
        </x-slide-over>
        <x-modal-input buttonTitle="+ Add" title="New Project">
            <livewire:project.add-empty />
        </x-modal-input>
    </div>
    <div class="subtitle">All your projects are here.</div>
    <div class="grid gap-2 lg:grid-cols-2">
        @forelse ($projects as $project)
            <div class="gap-2 border border-transparent cursor-pointer box group" x-data
                x-on:click="goto('{{ $project->uuid }}')">
                <a class="flex flex-col flex-1 mx-6 hover:no-underline"
                    href="{{ route('project.show', ['project_uuid' => data_get($project, 'uuid')]) }}">
                    <div class="font-bold text-white">{{ $project->name }}</div>
                    <div class="description ">
                        {{ $project->description }}</div>
                </a>
                <div class="flex items-center">
                    <a class="mx-4 rounded group-hover:text-white"
                        href="{{ route('project.edit', ['project_uuid' => data_get($project, 'uuid')]) }}">
                        <svg xmlns="http://www.w3.org/2000/svg" class="icon hover:text-warning" viewBox="0 0 24 24"
                            stroke-width="1.5" stroke="currentColor" fill="none" stroke-linecap="round"
                            stroke-linejoin="round">
                            <path stroke="none" d="M0 0h24v24H0z" fill="none" />
                            <path
                                d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z" />
                            <path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0" />
                        </svg>
                    </a>
                </div>
            </div>
        @empty
            <div>
                <div>No project found.</div>
            </div>
        @endforelse
    </div>

    <script>
        function goto(uuid) {
            window.location.href = '/project/' + uuid;
        }
    </script>
</div>
