<div x-data="{
        view: localStorage.getItem('projectView') === 'list' ? 'list' : 'card',
        setView(mode) {
            this.view = mode === 'list' ? 'list' : 'card';
            localStorage.setItem('projectView', this.view);
        },
    }">
    <x-slot:title>
        Projects | Coolify
    </x-slot>
    <div class="flex flex-wrap gap-2 items-center">
        <h1>Projects</h1>
        @can('createAnyResource')
            <x-modal-input buttonTitle="+ Add" title="New Project">
                <livewire:project.add-empty />
            </x-modal-input>
        @endcan
        @if ($projects->count() > 1)
            <div class="flex items-center gap-2 ml-auto">
                <div class="w-44">
                    <select wire:model.live="sort" aria-label="Sort projects" class="select text-xs">
                        <option value="name_asc">Name (A–Z)</option>
                        <option value="name_desc">Name (Z–A)</option>
                        <option value="created_desc">Recently created</option>
                        <option value="updated_desc">Recently updated</option>
                    </select>
                </div>
                <div class="flex items-center overflow-hidden border rounded border-neutral-200 dark:border-coolgray-400"
                    role="group" aria-label="Project view mode">
                    <button type="button" @click="setView('card')" title="Card view"
                        :aria-pressed="view === 'card' ? 'true' : 'false'"
                        class="flex items-center justify-center p-1.5 cursor-pointer"
                        :class="view === 'card' ? 'bg-neutral-200 dark:bg-coolgray-300 text-black dark:text-white' : 'text-neutral-500 hover:text-black dark:hover:text-white'">
                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                            stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 8.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25a2.25 2.25 0 01-2.25 2.25h-2.25A2.25 2.25 0 0113.5 8.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25a2.25 2.25 0 01-2.25-2.25v-2.25z" />
                        </svg>
                    </button>
                    <button type="button" @click="setView('list')" title="List view"
                        :aria-pressed="view === 'list' ? 'true' : 'false'"
                        class="flex items-center justify-center p-1.5 cursor-pointer"
                        :class="view === 'list' ? 'bg-neutral-200 dark:bg-coolgray-300 text-black dark:text-white' : 'text-neutral-500 hover:text-black dark:hover:text-white'">
                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                            stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 12h.007v.008H3.75V12zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zM3.75 17.25h.007v.008H3.75v-.008zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                        </svg>
                    </button>
                </div>
            </div>
        @endif
    </div>
    <div class="subtitle">All your projects are here.</div>
    @if ($projects->count() > 0)
        <div class="-mt-1" :class="view === 'list' ? 'overflow-hidden border rounded-lg border-neutral-200 dark:border-coolgray-300 divide-y divide-neutral-200 dark:divide-coolgray-300' : 'grid grid-cols-1 gap-4 xl:grid-cols-2'">
            @foreach ($this->sortedProjects as $project)
                <div wire:key="project-index-{{ $project->uuid }}" class="relative cursor-pointer group"
                    :class="view === 'list' ? 'flex items-center px-4 py-2 transition-colors hover:bg-neutral-100 dark:hover:bg-coolgray-200' : 'gap-2 coolbox'">
                    <a href="{{ $project->navigateTo() }}" {{ wireNavigate() }} class="absolute inset-0"></a>
                    <div class="flex flex-1 min-w-0" :class="view === 'list' ? 'items-center gap-3' : 'mx-6'">
                        <div class="flex-1 min-w-0" :class="view === 'list' ? 'flex items-baseline gap-2' : 'flex flex-col justify-center'">
                            <div class="box-title" :class="view === 'list' ? 'truncate shrink-0' : ''">{{ $project->name }}</div>
                            <div class="box-description" :class="view === 'list' ? 'flex-1 min-w-0 truncate' : ''">
                                {{ $project->description }}
                            </div>
                        </div>
                        <div class="relative z-10 flex items-center justify-center gap-4 text-xs font-bold shrink-0">
                            @if ($project->environments->first())
                                @can('createAnyResource')
                                    <a class="hover:underline" {{ wireNavigate() }}
                                        href="{{ route('project.resource.create', [
                                            'project_uuid' => $project->uuid,
                                            'environment_uuid' => $project->environments->first()->uuid,
                                        ]) }}">
                                        + Add Resource
                                    </a>
                                @endcan
                            @endif
                            @can('update', $project)
                                <a class="hover:underline" {{ wireNavigate() }}
                                    href="{{ route('project.edit', ['project_uuid' => $project->uuid]) }}">
                                    Settings
                                </a>
                            @endcan
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="flex flex-col gap-1">
            <div class='font-bold dark:text-warning'>No projects found.</div>
            <div class="flex items-center gap-1">
                @can('createAnyResource')
                    <x-modal-input buttonTitle="Add" title="New Project">
                        <livewire:project.add-empty />
                    </x-modal-input> your first project or
                @else
                    Create your first project or
                @endcan
                go to the <a class="underline dark:text-white" href="{{ route('onboarding') }}"
                    {{ wireNavigate() }}>onboarding</a> page.
            </div>
        </div>
    @endif
</div>
