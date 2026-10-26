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
        $hasTrafficAnalytics = $servers->contains(fn ($server) => $server->isTrafficAnalyticsEnabled());
        $projectResourceCounts = $dashboardProjects->mapWithKeys(fn ($project) => [$project->id => collect([
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
            $project->sqlites_count,
        ])->sum()]);
        $serverStatuses = $dashboardServers->mapWithKeys(function ($server) {
            $proxyNeedsAttention = $server->proxySet() && ($server->proxy->status !== 'running' || $server->hasCurrentTraefikOutdatedInfo());
            $sentinelNeedsAttention = $server->isSentinelEnabled() && $server->sentinelStatus() === 'out_of_sync';

            return [$server->id => match (true) {
                $server->settings->force_disabled => ['Disabled', 'error'],
                ! $server->settings->is_reachable && ! $server->settings->is_usable => ['Unavailable', 'error'],
                ! $server->settings->is_reachable => ['Unreachable', 'error'],
                ! $server->settings->is_usable => ['Not ready', 'warning'],
                $proxyNeedsAttention || $sentinelNeedsAttention => ['Attention required', 'warning'],
                default => ['Ready', 'success'],
            }];
        });
        $iconButton = 'card-icon-button flex size-6.5 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black';
        // Same look as $iconButton, sized to the 20px section title it sits next to.
        $titleIconButton = 'flex size-5 items-center justify-center rounded-md text-neutral-400 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg';
        $tableShell = 'overflow-x-auto rounded-xl border border-neutral-200 bg-white shadow-sm dark:border-white/[0.08] dark:bg-white/[0.05]';
        $tableHead = 'border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint';
        $tableRow = 'min-h-14 items-center border-b border-neutral-200 px-4 py-2.5 text-[12px] transition-colors last:border-b-0 hover:bg-neutral-50 dark:border-white/[0.07] dark:hover:bg-white/[0.025]';
    @endphp

    <div class="flex min-w-0 flex-col gap-8">
        @if ($pendingInvitations->isNotEmpty())
            <div class="flex min-w-0 flex-col gap-2">
                @foreach ($pendingInvitations as $invitation)
                    <x-callout type="info" title="Pending team invitation"
                        wire:key="dashboard-invitation-{{ $invitation->uuid }}">
                        <div class="flex min-w-0 flex-wrap items-center justify-between gap-2">
                            <span class="min-w-0">Team <span class="font-semibold">{{ $invitation->team->name }}</span>
                                invited you as {{ ucfirst($invitation->role) }}.</span>
                            <a href="{{ route('team.invitation.show', $invitation->uuid) }}" class="button shrink-0">
                                Review invitation
                            </a>
                        </div>
                    </x-callout>
                @endforeach
            </div>
        @endif

        {{-- Wide screens: projects and servers fill the left, activity is a fixed-width column on the right.
             It stays one column while the activity side has no section (no deployments, no traffic). --}}
        <div class="grid min-w-0 gap-8 xl:items-start xl:has-[.dashboard-activity_section]:grid-cols-[minmax(0,1fr)_22rem] 2xl:has-[.dashboard-activity_section]:grid-cols-[minmax(0,1fr)_26rem]">
        <div class="dashboard-activity hidden min-w-0 has-[section]:flex flex-col gap-8 xl:order-last">
        <livewire:dashboard.active-deployments />

        @if ($hasTrafficAnalytics)
            <livewire:dashboard.traffic-analytics />
        @endif
        </div>

        <div class="flex min-w-0 flex-col gap-8">
        <section class="@container mb-0! min-w-0" x-data="{
            viewMode: @js($this->listView('dashboard_projects', 'table')),
            setViewMode(mode) {
                this.viewMode = mode;
                $wire.saveListView('dashboard_projects', mode);
            }
        }">
            <x-section-heading title="Projects"
                :href="$projects->count() > $dashboardItemLimit ? route('project.index') : null">
                {{-- Projects has no sidebar entry, so the dashboard is where new projects start. --}}
                @can('createAnyResource')
                    <x-slot:titleAction>
                        <x-modal-input title="New Project">
                            <x-slot:content>
                                <button type="button" class="{{ $titleIconButton }}" title="New project"
                                    aria-label="New project">
                                    <x-reicon name="plus" class="size-3" />
                                </button>
                            </x-slot:content>
                            <livewire:project.add-empty />
                        </x-modal-input>
                    </x-slot:titleAction>
                @endcan
                {{-- The dashboard canvas link lives here, so it never adds a row above the page. --}}
                <x-slot:actions>
                    <x-canvas-view-toggle :views="$dashboardProjects->isNotEmpty() ? null : []"
                        :list-url="route('dashboard')" :canvas-url="route('dashboard.canvas')" />
                </x-slot:actions>
            </x-section-heading>

            @if ($dashboardProjects->isEmpty())
                <x-empty title="No projects yet"
                    description="Create your first deployment workspace."
                    icon-name="projects" size="sm" />
            @else
                <div x-cloak x-show="viewMode === 'grid'"
                    class="grid min-w-0 grid-cols-1 gap-3 @lg:grid-cols-2 @4xl:grid-cols-4">
                    @foreach ($dashboardProjects as $project)
                        @php
                            $firstEnvironment = $project->environments->first();
                            $resourceCount = $projectResourceCounts[$project->id];
                        @endphp

                        <article
                            class="group relative flex min-h-28 min-w-0 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.05] dark:hover:border-white/[0.14]">
                            <a href="{{ $project->navigateTo() }}" {{ wireNavigate() }}
                                class="absolute inset-0 rounded-xl"
                                aria-label="Open {{ $project->name }}"></a>

                            <div class="flex min-w-0 items-start gap-3">
                                <div
                                    class="resource-glyph flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500">
                                    @if ($project->icon_path)
                                        <img src="{{ project_icon_url($project) }}"
                                            alt="{{ $project->name }} icon"
                                            class="h-full w-full rounded-lg object-cover">
                                    @else
                                        <x-reicon name="projects" class="size-4" />
                                    @endif
                                </div>
                                <div class="min-w-0 flex-1 self-center">
                                    <h3
                                        class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                        {{ $project->name }}
                                    </h3>
                                    @if (filled($project->description))
                                        <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                            {{ $project->description }}
                                        </p>
                                    @endif
                                </div>
                            </div>

                            <div class="mt-auto flex items-center justify-between gap-3 border-t border-neutral-100 pt-2.5 dark:border-white/[0.06]">
                                <p class="project-card-stats relative z-10 min-w-0">
                                    <b>{{ $project->environments->count() }}</b>
                                    {{ $project->environments->count() === 1 ? 'environment' : 'environments' }}
                                    <span class="project-card-stats-dot" aria-hidden="true">&middot;</span>
                                    <b>{{ $resourceCount }}</b>
                                    {{ $resourceCount === 1 ? 'resource' : 'resources' }}
                                </p>

                                <div class="relative z-10 flex shrink-0 items-center gap-0.5">
                                    @if ($firstEnvironment)
                                        @can('createAnyResource')
                                            <a href="{{ route('project.resource.create', [
                                                'project_uuid' => $project->uuid,
                                                'environment_uuid' => $firstEnvironment->uuid,
                                            ]) }}"
                                                {{ wireNavigate() }}
                                                class="{{ $iconButton }}"
                                                title="Add resource"
                                                aria-label="Add resource to {{ $project->name }}">
                                                <x-reicon name="plus" class="size-3" />
                                            </a>
                                        @endcan
                                    @endif
                                    @can('update', $project)
                                        <a href="{{ route('project.edit', ['project_uuid' => $project->uuid]) }}"
                                            {{ wireNavigate() }}
                                            class="{{ $iconButton }}"
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

                <div x-show="viewMode === 'table'" class="{{ $tableShell }}">
                    <div class="grid grid-cols-[minmax(0,1fr)_7rem_4rem] gap-4 @2xl:min-w-[560px] @2xl:grid-cols-[minmax(0,1.2fr)_7rem_minmax(0,1.5fr)_4rem] {{ $tableHead }}">
                        <div>Project</div>
                        <div>Contents</div>
                        <div class="hidden @2xl:block">Description</div>
                        <div></div>
                    </div>
                    @foreach ($dashboardProjects as $project)
                        @php
                            $firstEnvironment = $project->environments->first();
                        @endphp
                        <div wire:key="dashboard-project-row-{{ $project->uuid }}"
                            class="relative grid grid-cols-[minmax(0,1fr)_7rem_4rem] gap-4 @2xl:min-w-[560px] @2xl:grid-cols-[minmax(0,1.2fr)_7rem_minmax(0,1.5fr)_4rem] {{ $tableRow }}">
                            <a href="{{ $project->navigateTo() }}" {{ wireNavigate() }}
                                class="absolute inset-0" aria-label="Open {{ $project->name }}"></a>
                            <div class="flex min-w-0 items-center gap-3">
                                <div
                                    class="resource-glyph flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500">
                                    @if ($project->icon_path)
                                        <img src="{{ project_icon_url($project) }}" alt="{{ $project->name }} icon"
                                            class="h-full w-full rounded-lg object-cover">
                                    @else
                                        <x-reicon name="projects" class="size-4" />
                                    @endif
                                </div>
                                <p class="truncate text-[13px] font-semibold text-black dark:text-fg">
                                    {{ $project->name }}
                                </p>
                            </div>
                            <div class="relative z-10 flex w-fit items-center gap-3 font-medium text-neutral-600 dark:text-fg-dim">
                                <span class="inline-flex items-center gap-1" data-tooltip="Environments"
                                    aria-label="Environments">
                                    <x-reicon name="layers" class="size-3.5 text-neutral-400 dark:text-fg-faint" />
                                    {{ $project->environments->count() }}
                                </span>
                                <span class="inline-flex items-center gap-1" data-tooltip="Resources"
                                    aria-label="Resources">
                                    <x-reicon name="grid" class="size-3.5 text-neutral-400 dark:text-fg-faint" />
                                    {{ $projectResourceCounts[$project->id] }}
                                </span>
                            </div>
                            <p class="hidden truncate text-neutral-500 @2xl:block dark:text-fg-dim">
                                {{ filled($project->description) ? $project->description : '-' }}
                            </p>
                            <div class="relative z-10 flex items-center justify-end gap-0.5">
                                @if ($firstEnvironment)
                                    @can('createAnyResource')
                                        <a href="{{ route('project.resource.create', [
                                            'project_uuid' => $project->uuid,
                                            'environment_uuid' => $firstEnvironment->uuid,
                                        ]) }}"
                                            {{ wireNavigate() }} class="{{ $iconButton }}" title="Add resource"
                                            aria-label="Add resource to {{ $project->name }}">
                                            <x-reicon name="plus" class="size-3" />
                                        </a>
                                    @endcan
                                @endif
                                @can('update', $project)
                                    <a href="{{ route('project.edit', ['project_uuid' => $project->uuid]) }}"
                                        {{ wireNavigate() }} class="{{ $iconButton }}" title="Project settings"
                                        aria-label="Open settings for {{ $project->name }}">
                                        <x-reicon name="settings" class="size-3" />
                                    </a>
                                @endcan
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="@container mb-0! min-w-0" x-data="{
            viewMode: @js($this->listView('dashboard_servers', 'table')),
            setViewMode(mode) {
                this.viewMode = mode;
                $wire.saveListView('dashboard_servers', mode);
            }
        }">
            <x-section-heading title="Servers"
                :href="$servers->count() > $dashboardItemLimit ? route('server.index') : null">
                @can('createAnyResource')
                    <x-slot:titleAction>
                        <a href="{{ route('server.create') }}" {{ wireNavigate() }} class="{{ $titleIconButton }}"
                            title="New server" aria-label="New server">
                            <x-reicon name="plus" class="size-3" />
                        </a>
                    </x-slot:titleAction>
                @endcan
                @if ($dashboardServers->isNotEmpty())
                    <x-slot:actions>
                        <x-canvas-view-toggle />
                    </x-slot:actions>
                @endif
            </x-section-heading>

            @if ($dashboardServers->isEmpty())
                @if ($privateKeys->isEmpty())
                    <x-empty title="A private key is required"
                        description="Add an SSH private key before connecting your first server."
                        icon-name="keys" size="sm">
                        @can('create', App\Models\PrivateKey::class)
                            <x-slot:contents>
                                <a href="{{ route('security.private-key.index') }}" {{ wireNavigate() }}
                                    class="button button-highlighted">
                                    <x-reicon name="plus" class="size-3.5" />
                                    Add private key
                                </a>
                            </x-slot:contents>
                        @endcan
                    </x-empty>
                @else
                    <x-empty title="No servers yet"
                        description="Connect infrastructure for your deployments."
                        icon-name="servers" size="sm">
                        @can('createAnyResource')
                            <x-slot:contents>
                                <a href="{{ route('server.create') }}" {{ wireNavigate() }}
                                    class="button button-highlighted">
                                    <x-reicon name="plus" class="size-3.5" />
                                    New server
                                </a>
                            </x-slot:contents>
                        @endcan
                    </x-empty>
                @endif
            @else
                <div x-cloak x-show="viewMode === 'grid'"
                    class="grid min-w-0 grid-cols-1 gap-3 @lg:grid-cols-2 @4xl:grid-cols-4">
                    @foreach ($dashboardServers as $server)
                        @php
                            [$serverStatus, $serverStatusType] = $serverStatuses[$server->id];
                        @endphp

                        <a href="{{ route('server.show', ['server_uuid' => $server->uuid]) }}"
                            {{ wireNavigate() }} aria-label="Open {{ $server->name }}"
                            class="group relative flex min-h-28 min-w-0 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.05] dark:hover:border-white/[0.14]">
                            @if ($server->isMetricsEnabled())
                                <livewire:dashboard.server-metrics-chart :server="$server"
                                    :key="'dashboard-server-metrics-'.$server->uuid" />
                            @endif

                            <div class="relative z-10 flex min-w-0 items-start gap-3">
                                <div
                                    class="resource-glyph flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500">
                                    <x-reicon name="servers" class="size-4" />
                                </div>
                                <div class="min-w-0 flex-1 self-center">
                                    <h3
                                        class="truncate text-[13px]! leading-4! font-semibold! text-black dark:text-fg">
                                        {{ $server->name }}
                                    </h3>
                                    @if (filled($server->description))
                                        <p class="mt-0.5 truncate text-[11px] text-neutral-500 dark:text-fg-faint">
                                            {{ $server->description }}
                                        </p>
                                    @endif
                                </div>
                                @if ($serverStatusType !== 'success')
                                    <span data-tooltip="{{ $serverStatus }}"
                                        aria-label="Server status: {{ $serverStatus }}"
                                        @class([
                                            'flex size-6 shrink-0 items-center justify-center rounded-md',
                                            'text-orange-500 dark:text-warning' => $serverStatusType === 'warning',
                                            'text-red-500 dark:text-red-400' => $serverStatusType === 'error',
                                        ])>
                                        <x-reicon name="alert-triangle" class="size-4" />
                                    </span>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
                @php
                    // A Usage column (latest CPU and memory) only when a listed server collects metrics.
                    $showServerUsage = $dashboardServers->contains(fn ($server) => $server->isMetricsEnabled());
                    $serverTableColumns = $showServerUsage
                        ? 'grid-cols-[minmax(0,1fr)_9.5rem] @xl:grid-cols-[minmax(0,1fr)_9rem_9.5rem] @2xl:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)_9rem_9.5rem]'
                        : 'grid-cols-[minmax(0,1fr)_9.5rem] @2xl:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)_9.5rem]';
                @endphp
                <div x-show="viewMode === 'table'" class="{{ $tableShell }}">
                    <div class="grid {{ $serverTableColumns }} gap-4 @2xl:min-w-[480px] {{ $tableHead }}">
                        <div>Server</div>
                        <div class="hidden @2xl:block">Description</div>
                        @if ($showServerUsage)
                            <div class="hidden @xl:block">Usage</div>
                        @endif
                        <div>Status</div>
                    </div>
                    @foreach ($dashboardServers as $server)
                        @php
                            [$serverStatus, $serverStatusType] = $serverStatuses[$server->id];
                        @endphp
                        <a href="{{ route('server.show', ['server_uuid' => $server->uuid]) }}" {{ wireNavigate() }}
                            wire:key="dashboard-server-row-{{ $server->uuid }}"
                            class="grid {{ $serverTableColumns }} gap-4 @2xl:min-w-[480px] hover:no-underline {{ $tableRow }}">
                            <div class="flex min-w-0 items-center gap-3">
                                <div
                                    class="resource-glyph flex size-8 shrink-0 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500">
                                    <x-reicon name="servers" class="size-4" />
                                </div>
                                <p class="truncate text-[13px] font-semibold text-black dark:text-fg">
                                    {{ $server->name }}
                                </p>
                            </div>
                            <p class="hidden truncate text-neutral-500 @2xl:block dark:text-fg-dim">
                                {{ filled($server->description) ? $server->description : '-' }}
                            </p>
                            @if ($showServerUsage)
                                <div class="hidden @xl:block">
                                    @if ($server->isMetricsEnabled())
                                        {{-- The latest values from the grid card's chart (server-metrics-chart), so the table sends no requests of its own. --}}
                                        <div class="flex flex-col gap-1 text-[11px] text-neutral-500 tabular-nums dark:text-fg-dim"
                                            x-data="{
                                                cpu: null,
                                                memory: null,
                                                stop: null,
                                                init() {
                                                    const latest = points => points?.length ? points[points.length - 1][1] : null;
                                                    this.stop = Livewire.on(@js('dashboard-server-metrics-'.$server->uuid), data => {
                                                        this.cpu = latest(data[0]?.cpu);
                                                        this.memory = latest(data[0]?.memory);
                                                    });
                                                },
                                                destroy() {
                                                    this.stop?.();
                                                },
                                            }">
                                            @foreach (['cpu' => ['CPU', 'bg-[rgb(30,144,255)]'], 'memory' => ['Mem', 'bg-[rgb(168,85,247)]']] as $metric => [$metricLabel, $metricColor])
                                                <div class="flex items-center gap-2">
                                                    <span class="w-7 shrink-0">{{ $metricLabel }}</span>
                                                    <span class="h-1 min-w-0 flex-1 overflow-hidden rounded-full bg-neutral-200 dark:bg-white/[0.08]">
                                                        <span class="block h-full rounded-full {{ $metricColor }} transition-[width] duration-500"
                                                            :style="{ width: `${Math.min(100, {{ $metric }} ?? 0)}%` }"></span>
                                                    </span>
                                                    <span class="w-8 shrink-0 text-right"
                                                        x-text="{{ $metric }} === null ? '-' : `${Math.round({{ $metric }})}%`">-</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-neutral-500 dark:text-fg-dim">-</span>
                                    @endif
                                </div>
                            @endif
                            <div class="flex items-center gap-2 text-[11px] font-medium text-neutral-600 dark:text-fg-dim">
                                <span @class([
                                    'size-2 shrink-0 rounded-full',
                                    'bg-green-500 dark:bg-green-400' => $serverStatusType === 'success',
                                    'bg-orange-500 dark:bg-warning' => $serverStatusType === 'warning',
                                    'bg-red-500 dark:bg-red-400' => $serverStatusType === 'error',
                                ])></span>
                                {{ $serverStatus }}
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
        </div>
        </div>
    </div>
</div>
