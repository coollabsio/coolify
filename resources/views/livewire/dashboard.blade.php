<div>
    <x-slot:title>
        Dashboard | Coolify
    </x-slot>
    @if (session('error'))
        <span x-data x-init="$wire.emit('error', '{{ session('error') }}')" />
    @endif

    <div class="flex flex-col gap-1 pb-4">
        <h1>Dashboard</h1>
        <div class="subtitle">Your self-hosted infrastructure.</div>
    </div>

    <section class="pb-8">
        <div class="flex items-center justify-between gap-2 pb-4">
            <div class="flex items-center gap-2">
                <h3>Projects</h3>
                <span class="text-xs text-neutral-500 dark:text-coolgray-500">{{ $projects->count() }}</span>
            </div>
            @can('create', App\Models\Project::class)
                @if ($projects->count() > 0)
                    <x-modal-input title="New Project">
                        <x-slot:content>
                            <button type="button" class="button-primary">
                                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none"
                                    viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                                New Project
                            </button>
                        </x-slot:content>
                        <livewire:project.add-empty />
                    </x-modal-input>
                @endif
            @endcan
        </div>

        @if ($projects->count() > 0)
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2 xl:grid-cols-3">
                @foreach ($projects as $project)
                    @php
                        $resourceCount = $project->environments->sum(
                            fn($env) => $env->applications->count() + $env->databases()->count() + $env->services->count(),
                        );
                        $runningCount = $project->environments->sum(
                            fn($env) => $env->applications->filter(fn($a) => str($a->status)->startsWith('running'))->count(),
                        );
                    @endphp
                    <div
                        class="relative flex flex-col gap-4 p-5 border rounded-xl cursor-pointer group border-neutral-200 dark:border-coolgray-300 bg-white dark:bg-coolgray-100 hover:border-coollabs hover:shadow-md dark:hover:shadow-none transition-all">
                        <a href="{{ $project->navigateTo() }}" {{ wireNavigate() }} class="absolute inset-0"></a>
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-start flex-1 min-w-0 gap-3">
                                <div class="resource-avatar">
                                    <svg class="size-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-19.5 0v6a2.25 2.25 0 002.25 2.25h15a2.25 2.25 0 002.25-2.25v-6m-19.5 0a2.25 2.25 0 012.25-2.25h15a2.25 2.25 0 012.25 2.25m-19.5 0v-1.5a2.25 2.25 0 012.25-2.25h6a2.25 2.25 0 012.25 2.25v1.5" />
                                    </svg>
                                </div>
                                <div class="flex flex-col min-w-0 gap-1 pt-0.5">
                                    <div class="font-semibold truncate text-black dark:text-white">{{ $project->name }}</div>
                                    <div class="text-sm text-neutral-500 dark:text-coolgray-500 line-clamp-2">
                                        {{ $project->description ?: 'No description' }}
                                    </div>
                                </div>
                            </div>
                            @if ($resourceCount > 0)
                                <span
                                    class="flex items-center gap-1.5 text-xs font-medium px-2 py-1 rounded-full bg-neutral-100 dark:bg-coolgray-200 text-neutral-600 dark:text-coolgray-500 shrink-0">
                                    <span
                                        class="size-1.5 rounded-full {{ $runningCount > 0 ? 'bg-success' : 'bg-neutral-400 dark:bg-coolgray-400' }}"></span>
                                    {{ $runningCount }}/{{ $resourceCount }} running
                                </span>
                            @endif
                        </div>

                        <div class="flex items-center justify-between pt-3 mt-auto border-t border-neutral-100 dark:border-coolgray-300">
                            <span class="text-xs text-neutral-400 dark:text-coolgray-500">
                                {{ $project->environments->count() }} {{ Str::plural('environment', $project->environments->count()) }}
                            </span>
                            <div class="relative z-10 flex items-center gap-4 text-xs font-semibold">
                                @if ($project->environments->first())
                                    @can('createAnyResource')
                                        <a class="hover:underline hover:text-coollabs" {{ wireNavigate() }}
                                            href="{{ route('project.resource.create', [
                                                'project_uuid' => $project->uuid,
                                                'environment_uuid' => $project->environments->first()->uuid,
                                            ]) }}">
                                            + Add Resource
                                        </a>
                                    @endcan
                                @endif
                                @can('update', $project)
                                    <a class="hover:underline hover:text-coollabs" {{ wireNavigate() }}
                                        href="{{ route('project.edit', ['project_uuid' => $project->uuid]) }}">
                                        Settings
                                    </a>
                                @endcan
                            </div>
                        </div>
                    </div>
                @endforeach
                @can('create', App\Models\Project::class)
                    <x-modal-input title="New Project">
                        <x-slot:content>
                            <button type="button"
                                class="flex flex-col items-center justify-center w-full h-full gap-2 p-5 text-center transition-colors border border-dashed rounded-xl min-h-[9rem] border-neutral-300 dark:border-coolgray-400 text-neutral-500 dark:text-coolgray-500 hover:border-coollabs hover:text-coollabs">
                                <span class="flex items-center justify-center text-lg rounded-full size-9 bg-neutral-100 dark:bg-coolgray-200">+</span>
                                <span class="text-sm font-medium">Create New Project</span>
                            </button>
                        </x-slot:content>
                        <livewire:project.add-empty />
                    </x-modal-input>
                @endcan
            </div>
        @else
            <div class="flex flex-col items-center justify-center gap-3 p-10 text-center border border-dashed rounded-xl border-neutral-300 dark:border-coolgray-400">
                <span class="flex items-center justify-center text-xl rounded-full size-11 bg-neutral-100 dark:bg-coolgray-200 text-neutral-400 dark:text-coolgray-500">+</span>
                <div class="font-semibold text-black dark:text-white">No projects found</div>
                @can('create', App\Models\Project::class)
                    <div class="flex items-center gap-3">
                        <x-modal-input title="New Project">
                            <x-slot:content>
                                <button type="button" class="button-primary">+ New Project</button>
                            </x-slot:content>
                            <livewire:project.add-empty />
                        </x-modal-input>
                        <a class="text-sm underline text-neutral-500 dark:text-coolgray-500 hover:text-black dark:hover:text-white"
                            href="{{ route('onboarding') }}" {{ wireNavigate() }}>go to onboarding</a>
                    </div>
                @endcan
            </div>
        @endif
    </section>

    <section>
        <div class="flex items-center justify-between gap-2 pb-4">
            <div class="flex items-center gap-2">
                <h3>Servers</h3>
                <span class="text-xs text-neutral-500 dark:text-coolgray-500">{{ $servers->count() }}</span>
            </div>
            @can('create', App\Models\Server::class)
                @if ($servers->count() > 0 && $privateKeys->count() > 0)
                    <a href="{{ route('server.create') }}" {{ wireNavigate() }} class="button-primary">
                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none"
                            viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        New Server
                    </a>
                @endif
            @endcan
        </div>

        @if ($servers->count() > 0)
            <div class="overflow-hidden border rounded-xl border-neutral-200 dark:border-coolgray-300">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-left uppercase bg-neutral-50 dark:bg-coolgray-200 text-neutral-500 dark:text-coolgray-500">
                            <th class="px-5 py-3 font-medium">Server</th>
                            <th class="px-5 py-3 font-medium">IP Address</th>
                            <th class="px-5 py-3 font-medium">Status</th>
                            <th class="px-5 py-3 font-medium text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-coolgray-300">
                        @foreach ($servers as $server)
                            @php
                                $unreachable = !$server->settings->is_reachable || $server->settings->force_disabled;
                                $unusable = !$server->settings->is_usable;
                            @endphp
                            <tr class="transition-colors hover:bg-neutral-50 dark:hover:bg-coolgray-200">
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="resource-avatar size-8 rounded-md">
                                            <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 17.25v-.228a4.5 4.5 0 00-.12-1.03l-2.268-9.64a3.375 3.375 0 00-3.285-2.602H7.923a3.375 3.375 0 00-3.285 2.602l-2.268 9.64a4.5 4.5 0 00-.12 1.03v.228m19.5 0a3 3 0 01-3 3H5.25a3 3 0 01-3-3m19.5 0a3 3 0 00-3-3H5.25a3 3 0 00-3 3m16.5 0h.008v.008h-.008v-.008zm-3 0h.008v.008h-.008v-.008z" />
                                            </svg>
                                        </div>
                                        <div>
                                            <a href="{{ route('server.show', ['server_uuid' => data_get($server, 'uuid')]) }}"
                                                {{ wireNavigate() }} class="font-medium text-black dark:text-white hover:underline">
                                                {{ $server->name }}
                                            </a>
                                            @if ($server->description)
                                                <div class="text-xs text-neutral-400 dark:text-coolgray-500">{{ $server->description }}</div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-3 font-mono text-xs text-neutral-600 dark:text-coolgray-500">
                                    {{ $server->ip }}
                                </td>
                                <td class="px-5 py-3">
                                    @if ($unreachable || $unusable)
                                        <span class="flex items-center gap-1.5 text-xs font-medium text-error">
                                            <span class="size-1.5 rounded-full bg-error"></span>
                                            @if ($unreachable) Not reachable @endif
                                            @if ($unreachable && $unusable) & @endif
                                            @if ($unusable) Not usable @endif
                                        </span>
                                    @else
                                        <span class="flex items-center gap-1.5 text-xs font-medium text-success">
                                            <span class="size-1.5 rounded-full bg-success"></span>
                                            Reachable
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right">
                                    <a href="{{ route('server.show', ['server_uuid' => data_get($server, 'uuid')]) }}"
                                        {{ wireNavigate() }}
                                        class="text-xs font-semibold hover:underline hover:text-coollabs">
                                        Manage
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            @if ($privateKeys->count() === 0)
                <div class="flex flex-col items-center justify-center gap-3 p-10 text-center border border-dashed rounded-xl border-neutral-300 dark:border-coolgray-400">
                    <span class="flex items-center justify-center text-xl rounded-full size-11 bg-neutral-100 dark:bg-coolgray-200 text-neutral-400 dark:text-coolgray-500">+</span>
                    <div class="font-semibold text-black dark:text-white">No private keys found</div>
                    <p class="max-w-sm text-sm text-neutral-500 dark:text-coolgray-500">Before you can add your server, first add a private key.</p>
                    @can('create', App\Models\Server::class)
                        <div class="flex items-center gap-3">
                            <x-modal-input title="New Private Key">
                                <x-slot:content>
                                    <button type="button" class="button-primary">+ Add Private Key</button>
                                </x-slot:content>
                                <livewire:security.private-key.create from="server" />
                            </x-modal-input>
                            <a class="text-sm underline text-neutral-500 dark:text-coolgray-500 hover:text-black dark:hover:text-white"
                                href="{{ route('onboarding') }}" {{ wireNavigate() }}>go to onboarding</a>
                        </div>
                    @endcan
                </div>
            @else
                <div class="flex flex-col items-center justify-center gap-3 p-10 text-center border border-dashed rounded-xl border-neutral-300 dark:border-coolgray-400">
                    <span class="flex items-center justify-center text-xl rounded-full size-11 bg-neutral-100 dark:bg-coolgray-200 text-neutral-400 dark:text-coolgray-500">+</span>
                    <div class="font-semibold text-black dark:text-white">No servers found</div>
                    @can('create', App\Models\Server::class)
                        <div class="flex items-center gap-3">
                            <a href="{{ route('server.create') }}" {{ wireNavigate() }} class="button-primary">+ New Server</a>
                            <a class="text-sm underline text-neutral-500 dark:text-coolgray-500 hover:text-black dark:hover:text-white"
                                href="{{ route('onboarding') }}" {{ wireNavigate() }}>go to onboarding</a>
                        </div>
                    @endcan
                </div>
            @endif
        @endif
    </section>
</div>
