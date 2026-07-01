<div>
    <x-slot:title>
        Dashboard | Coolify
    </x-slot>
    @if (session('error'))
        <span x-data x-init="$wire.emit('error', '{{ session('error') }}')" />
    @endif
    <h1>Dashboard</h1>
    <div class="subtitle">Your self-hosted infrastructure.</div>

    <section class="-mt-2"
        x-data="{
            view: localStorage.getItem('projectView') === 'list' ? 'list' : 'card',
            setView(mode) {
                this.view = mode === 'list' ? 'list' : 'card';
                localStorage.setItem('projectView', this.view);
            },
        }">
        <div class="flex flex-wrap items-center gap-2 pb-2">
            <h3>Projects</h3>
@can('create', App\Models\Project::class)
                @if ($projects->count() > 0)
                    <x-modal-input buttonTitle="Add" title="New Project">
                        <x-slot:content>
                            <button
                                class="flex items-center justify-center size-4 text-black dark:text-white rounded hover:bg-coolgray-400 dark:hover:bg-coolgray-300 cursor-pointer">
                                <svg class="size-3" xmlns="http://www.w3.org/2000/svg" fill="none"
                                    viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                            </button>
                        </x-slot:content>
                        <livewire:project.add-empty />
                    </x-modal-input>
                @endif
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
        @if ($projects->count() > 0)
            <div :class="view === 'list' ? 'overflow-hidden border rounded-lg border-neutral-200 dark:border-coolgray-300 divide-y divide-neutral-200 dark:divide-coolgray-300' : 'grid grid-cols-1 gap-4 xl:grid-cols-2'">
                @foreach ($this->sortedProjects as $project)
                    <div wire:key="dashboard-project-{{ $project->uuid }}" class="relative cursor-pointer group"
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
                @can('create', App\Models\Project::class)
                    <div class="flex items-center gap-1">
                        <x-modal-input buttonTitle="Add" title="New Project">
                            <livewire:project.add-empty />
                        </x-modal-input> your first project or
                        go to the <a class="underline dark:text-white" href="{{ route('onboarding') }}"
                            {{ wireNavigate() }}>onboarding</a> page.
                    </div>
                @endcan
            </div>
        @endif
    </section>

    <section>
        <div class="flex items-center gap-2 pb-2">
            <h3>Servers</h3>
@can('create', App\Models\Server::class)
                @if ($servers->count() > 0 && $privateKeys->count() > 0)
                    <x-modal-input buttonTitle="Add" title="New Server" :closeOutside="false">
                        <x-slot:content>
                            <button
                                class="flex items-center justify-center size-4 text-black dark:text-white rounded hover:bg-coolgray-400 dark:hover:bg-coolgray-300 cursor-pointer">
                                <svg class="size-3" xmlns="http://www.w3.org/2000/svg" fill="none"
                                    viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                            </button>
                        </x-slot:content>
                        <livewire:server.create />
                    </x-modal-input>
                @endif
            @endcan
        </div>
        @if ($servers->count() > 0)
            <div class="grid grid-cols-1 gap-4 xl:grid-cols-2">
                @foreach ($servers as $server)
                    <a href="{{ route('server.show', ['server_uuid' => data_get($server, 'uuid')]) }}" {{ wireNavigate() }}
                        @class([
                            'gap-2 border cursor-pointer coolbox group',
                            'border-red-500' =>
                                !$server->settings->is_reachable || $server->settings->force_disabled,
                        ])>
                        <div class="flex flex-col justify-center mx-6">
                            <div class="box-title">
                                {{ $server->name }}
                            </div>
                            <div class="box-description">
                                {{ $server->description }}</div>
                            <div class="flex gap-1 text-xs text-error">
                                @if (!$server->settings->is_reachable)
                                    Not reachable
                                @endif
                                @if (!$server->settings->is_reachable && !$server->settings->is_usable)
                                    &
                                @endif
                                @if (!$server->settings->is_usable)
                                    Not usable by Coolify
                                @endif
                            </div>
                        </div>
                        <div class="flex-1"></div>
                    </a>
                @endforeach
            </div>
        @else
            @if ($privateKeys->count() === 0)
                <div class="flex flex-col gap-1">
                    <div class='font-bold dark:text-warning'>No private keys found.</div>
                    @can('create', App\Models\Server::class)
                        <div class="flex items-center gap-1">Before you can add your server, first <x-modal-input
                                buttonTitle="add" title="New Private Key">
                                <livewire:security.private-key.create from="server" />
                            </x-modal-input> a private key
                            or
                            go to the <a class="underline dark:text-white"
                                href="{{ route('onboarding') }}"
                                {{ wireNavigate() }}>onboarding</a>
                            page.
                        </div>
                    @endcan
                </div>
            @else
                <div class="flex flex-col gap-1">
                    <div class='font-bold dark:text-warning'>No servers found.</div>
                    @can('create', App\Models\Server::class)
                        <div class="flex items-center gap-1">
                            <x-modal-input buttonTitle="Add" title="New Server" :closeOutside="false">
                                <livewire:server.create />
                            </x-modal-input> your first server
                            or
                            go to the <a class="underline dark:text-white"
                                href="{{ route('onboarding') }}"
                                {{ wireNavigate() }}>onboarding</a>
                            page.
                        </div>
                    @endcan
                </div>
            @endif
        @endif
    </section>
</div>
