<div class="application-settings-form w-full">
    <x-slot:title>
        Dashboard | Coolify
    </x-slot>

    @if (session('error'))
        <span x-data x-init="$wire.dispatch('error', @js(session('error')))" />
    @endif

    @php
        $dashboardItemLimit = 8;
        $dashboardProjects = $projects->sortBy('name', SORT_NATURAL)->take($dashboardItemLimit);
        $dashboardServers = $servers->sortBy('name', SORT_NATURAL)->take($dashboardItemLimit);
    @endphp

    <div class="flex min-w-0 flex-col gap-8">
        <livewire:dashboard.active-deployments />

        <section class="mb-0! min-w-0">
            <div class="mb-3 flex items-end justify-between gap-4">
                <div>
                    <h2 class="text-[14px]! leading-5! font-semibold! text-black dark:text-fg">
                        Projects
                    </h2>
                    <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint">
                        Your deployment workspaces
                    </p>
                </div>
                <a href="{{ route('project.index') }}" {{ wireNavigate() }}
                    class="inline-flex shrink-0 items-center gap-1 text-[12px] font-medium text-neutral-500 transition-colors hover:text-black dark:text-fg-dim dark:hover:text-fg">
                    View all
                    <x-reicon name="arrow-right" class="size-3" />
                </a>
            </div>

            @if ($dashboardProjects->isEmpty())
                <div
                    class="flex min-h-44 flex-col items-center justify-center rounded-xl border border-dashed border-neutral-300 bg-neutral-50 px-6 text-center dark:border-white/[0.1] dark:bg-white/[0.02]">
                    <div
                        class="mb-3 flex size-10 items-center justify-center rounded-xl border border-neutral-200 bg-white text-neutral-400 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-faint">
                        <x-reicon name="projects" class="size-5" />
                    </div>
                    <h3 class="text-[14px]! font-semibold!">No projects yet</h3>
                    <p class="mt-1 max-w-sm text-[12px] text-neutral-500 dark:text-fg-dim">
                        Use New to create your first deployment workspace.
                    </p>
                </div>
            @else
                <div class="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($dashboardProjects as $project)
                        @php
                            $firstEnvironment = $project->environments->first();
                            $resourceCount = collect([
                                $project->applications_count,
                                $project->services_count,
                                $project->postgresqls_count,
                                $project->redis_count,
                                $project->keydbs_count,
                                $project->dragonflies_count,
                                $project->clickhouses_count,
                                $project->mongodbs_count,
                                $project->mysqls_count,
                                $project->mariadbs_count,
                            ])->sum();
                        @endphp

                        <article
                            class="group relative flex min-h-28 min-w-0 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]">
                            <a href="{{ $project->navigateTo() }}" {{ wireNavigate() }}
                                class="absolute inset-0 rounded-xl"
                                aria-label="Open {{ $project->name }}"></a>

                            <div class="flex min-w-0 items-start gap-3">
                                <div
                                    class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                    <x-reicon name="projects" class="size-4" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h3
                                        class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                        {{ $project->name }}
                                    </h3>
                                    <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                        {{ $project->description ?: 'No description' }}
                                    </p>
                                </div>
                            </div>

                            <div class="mt-auto flex items-center justify-between gap-3 pt-4">
                                <p class="min-w-0 truncate text-[11px] text-neutral-500 dark:text-fg-dim">
                                    {{ $project->environments->count() }}
                                    {{ str('env')->plural($project->environments->count()) }}
                                    <span class="px-1 text-neutral-300 dark:text-white/15">·</span>
                                    {{ $resourceCount }} {{ str('resource')->plural($resourceCount) }}
                                </p>

                                <div class="relative z-10 flex shrink-0 items-center gap-0.5">
                                    @if ($firstEnvironment)
                                        @can('createAnyResource')
                                            <a href="{{ route('project.resource.create', [
                                                'project_uuid' => $project->uuid,
                                                'environment_uuid' => $firstEnvironment->uuid,
                                            ]) }}"
                                                {{ wireNavigate() }}
                                                class="flex size-6.5 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                                title="Add resource"
                                                aria-label="Add resource to {{ $project->name }}">
                                                <x-reicon name="plus" class="size-3" />
                                            </a>
                                        @endcan
                                    @endif
                                    @can('update', $project)
                                        <a href="{{ route('project.edit', ['project_uuid' => $project->uuid]) }}"
                                            {{ wireNavigate() }}
                                            class="flex size-6.5 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg"
                                            title="Project settings"
                                            aria-label="Open settings for {{ $project->name }}">
                                            <x-reicon name="settings" class="size-3" />
                                        </a>
                                    @endcan
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="mb-0! min-w-0">
            <div class="mb-3 flex items-end justify-between gap-4">
                <div>
                    <h2 class="text-[14px]! leading-5! font-semibold! text-black dark:text-fg">
                        Servers
                    </h2>
                    <p class="mt-0.5 text-[11px] text-neutral-500 dark:text-fg-faint">
                        Infrastructure available for deployments
                    </p>
                </div>
                <a href="{{ route('server.index') }}" {{ wireNavigate() }}
                    class="inline-flex shrink-0 items-center gap-1 text-[12px] font-medium text-neutral-500 transition-colors hover:text-black dark:text-fg-dim dark:hover:text-fg">
                    View all
                    <x-reicon name="arrow-right" class="size-3" />
                </a>
            </div>

            @if ($dashboardServers->isEmpty())
                <div
                    class="flex min-h-44 flex-col items-center justify-center rounded-xl border border-dashed border-neutral-300 bg-neutral-50 px-6 text-center dark:border-white/[0.1] dark:bg-white/[0.02]">
                    <div
                        class="mb-3 flex size-10 items-center justify-center rounded-xl border border-neutral-200 bg-white text-neutral-400 shadow-sm dark:border-white/[0.08] dark:bg-white/[0.035] dark:text-fg-faint">
                        <x-reicon name="servers" class="size-5" />
                    </div>
                    <h3 class="text-[14px]! font-semibold!">
                        {{ $privateKeys->isEmpty() ? 'A private key is required' : 'No servers yet' }}
                    </h3>
                    <p class="mt-1 max-w-sm text-[12px] text-neutral-500 dark:text-fg-dim">
                        {{ $privateKeys->isEmpty()
                            ? 'Use New to add a private key before connecting your first server.'
                            : 'Use New to connect infrastructure for your deployments.' }}
                    </p>
                </div>
            @else
                <div class="grid min-w-0 grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($dashboardServers as $server)
                        @php
                            [$serverStatus, $serverStatusType] = match (true) {
                                $server->settings->force_disabled => ['Disabled', 'error'],
                                ! $server->settings->is_reachable && ! $server->settings->is_usable => ['Unavailable', 'error'],
                                ! $server->settings->is_reachable => ['Unreachable', 'error'],
                                ! $server->settings->is_usable => ['Not ready', 'warning'],
                                default => ['Ready', 'success'],
                            };
                        @endphp

                        <article
                            class="group relative flex min-h-28 min-w-0 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]">
                            <a href="{{ route('server.show', ['server_uuid' => $server->uuid]) }}"
                                {{ wireNavigate() }} class="absolute inset-0 rounded-xl"
                                aria-label="Open {{ $server->name }}"></a>

                            <div class="flex min-w-0 items-start gap-3">
                                <div
                                    class="flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                    <x-reicon name="servers" class="size-4" />
                                </div>
                                <div class="min-w-0 flex-1">
                                    <h3
                                        class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                        {{ $server->name }}
                                    </h3>
                                    <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                        {{ $server->description ?: 'No description' }}
                                    </p>
                                </div>
                            </div>

                            <div class="mt-auto flex items-center justify-between gap-3 pt-4">
                                <x-status-badge :status="$serverStatus" :type="$serverStatusType" />
                                <span
                                    class="relative z-10 flex size-6.5 items-center justify-center rounded-md text-neutral-400 transition-colors group-hover:bg-neutral-100 group-hover:text-black dark:text-fg-faint dark:group-hover:bg-white/[0.06] dark:group-hover:text-fg">
                                    <x-reicon name="arrow-right" class="size-3" />
                                </span>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</div>
