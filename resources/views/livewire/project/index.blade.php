<div>
    <x-slot:title>
        Projects | Coolify
    </x-slot>
    <div class="flex items-start justify-between gap-2">
        <div class="flex flex-col gap-1">
            <h1>Projects</h1>
            <div class="subtitle">All your projects are here.</div>
        </div>
        @can('createAnyResource')
            <x-modal-input title="New Project">
                <x-slot:content>
                    <button type="button" class="button-primary">
                        <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                            stroke-width="2.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        New Project
                    </button>
                </x-slot:content>
                <livewire:project.add-empty />
            </x-modal-input>
        @endcan
    </div>
    @if ($projects->count() > 0)
        <div class="grid grid-cols-1 gap-4 pt-6 lg:grid-cols-2 xl:grid-cols-3">
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
            @can('createAnyResource')
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
        <div class="flex flex-col items-center justify-center gap-3 p-10 mt-6 text-center border border-dashed rounded-xl border-neutral-300 dark:border-coolgray-400">
            <span class="flex items-center justify-center text-xl rounded-full size-11 bg-neutral-100 dark:bg-coolgray-200 text-neutral-400 dark:text-coolgray-500">+</span>
            <div class="font-semibold text-black dark:text-white">No projects found</div>
            @can('createAnyResource')
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
            @else
                <p class="text-sm text-neutral-500 dark:text-coolgray-500">Contact your team administrator to create a
                    project, or go to the <a class="underline" href="{{ route('onboarding') }}" {{ wireNavigate() }}>onboarding</a> page.</p>
            @endcan
        </div>
    @endif
</div>
