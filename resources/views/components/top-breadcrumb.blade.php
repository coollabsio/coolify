@php
    $team = auth()->user()?->currentTeam();
    $projectUuid = request()->route('project_uuid');
    $environmentUuid = request()->route('environment_uuid');
    $projects = $projectUuid && $team ? $team->projects()->get() : collect();
    $currentProject = $projectUuid ? $projects->firstWhere('uuid', $projectUuid) : null;
    $environments = $currentProject ? $currentProject->environments()->get() : collect();
    $currentEnvironment = $environmentUuid ? $environments->firstWhere('uuid', $environmentUuid) : null;
    $projectDestinationRoute = request()->routeIs('shared-variables.project.*')
        ? 'shared-variables.project.show'
        : 'project.show';
    $applicationUuid = request()->route('application_uuid');
    $currentApplication = $currentEnvironment && $applicationUuid
        ? $currentEnvironment->applications()->where('uuid', $applicationUuid)->first()
        : null;
    $databaseUuid = request()->route('database_uuid');
    $currentDatabase = $currentEnvironment && $databaseUuid
        ? $currentEnvironment->databases()->firstWhere('uuid', $databaseUuid)
        : null;
    $serviceUuid = request()->route('service_uuid');
    $currentService = $currentEnvironment && $serviceUuid
        ? $currentEnvironment->services()->where('uuid', $serviceUuid)->first()
        : null;
    $currentResource = $currentApplication ?? $currentDatabase ?? $currentService;
    $resourceItems = $currentEnvironment
        ? collect()
            ->concat($currentEnvironment->applications->map(fn ($application) => [
                'type' => 'application',
                'resource' => $application,
            ]))
            ->concat($currentEnvironment->databases()->map(fn ($database) => [
                'type' => 'database',
                'resource' => $database,
            ]))
            ->concat($currentEnvironment->services->map(fn ($service) => [
                'type' => 'service',
                'resource' => $service,
            ]))
            ->sortBy(fn ($item) => strtolower($item['resource']->name))
            ->map(fn ($item) => [
                'label' => $item['resource']->name,
                'href' => match ($item['type']) {
                    'application' => route('project.application.configuration', [
                        'project_uuid' => $currentProject->uuid,
                        'environment_uuid' => $currentEnvironment->uuid,
                        'application_uuid' => $item['resource']->uuid,
                    ]),
                    'database' => route('project.database.configuration', [
                        'project_uuid' => $currentProject->uuid,
                        'environment_uuid' => $currentEnvironment->uuid,
                        'database_uuid' => $item['resource']->uuid,
                    ]),
                    'service' => route('project.service.configuration', [
                        'project_uuid' => $currentProject->uuid,
                        'environment_uuid' => $currentEnvironment->uuid,
                        'service_uuid' => $item['resource']->uuid,
                    ]),
                },
                'active' => $item['resource']->uuid === $currentResource?->uuid,
            ])
            ->values()
        : collect();
    $storageUuid = request()->route('storage_uuid');
    $storages = $storageUuid && $team ? \App\Models\S3Storage::ownedByCurrentTeam()->orderBy('name')->get() : collect();
    $currentStorage = $storages->firstWhere('uuid', $storageUuid);
    $githubAppUuid = request()->route('github_app_uuid');
    $gitlabAppUuid = request()->route('gitlab_app_uuid');
    $sourceUuid = $githubAppUuid ?? $gitlabAppUuid;
    $sources = $sourceUuid && $team ? $team->sources()->sortBy('name')->values() : collect();
    $currentSource = $sources->firstWhere('uuid', $sourceUuid);
    $destinationUuid = request()->route('destination_uuid');
    $destinations = $destinationUuid && $team
        ? \App\Models\Server::isUsable()
            ->with(['standaloneDockers', 'swarmDockers'])
            ->get()
            ->flatMap(fn ($server) => $server->standaloneDockers->concat($server->swarmDockers))
            ->sortBy('name')
            ->values()
        : collect();
    $currentDestination = $destinations->firstWhere('uuid', $destinationUuid);
    $tagName = request()->route('tagName');
    $tags = $tagName && $team
        ? \App\Models\Tag::ownedByCurrentTeam()->orderBy('name')->get()->unique('name')->values()
        : collect();
    $currentTag = $tags->firstWhere('name', $tagName);
    $dashboardContext = match (true) {
        request()->routeIs('dashboard') => 'Dashboard',
        request()->routeIs('project.index') => 'Projects',
        request()->routeIs('terminal') => 'Terminal',
        request()->routeIs('server.*') => 'Servers',
        request()->routeIs('source.*') => 'Sources',
        request()->routeIs('destination.*') => 'Destinations',
        request()->routeIs('storage.*') => 'S3 Storage',
        request()->routeIs('shared-variables.*') => 'Shared Variables',
        request()->routeIs('team.*') => 'Team',
        request()->routeIs('notifications.*') => 'Notifications',
        request()->routeIs('security.*') => 'Keys & Tokens',
        request()->routeIs('tags.*') => 'Tags',
        request()->routeIs('settings.*') => 'Settings',
        request()->routeIs('profile*') => 'Profile',
        request()->routeIs('admin.*') => 'Admin',
        default => null,
    };
    // Workspace destinations require an active plan on cloud; unsubscribed users
    // only keep profile/appearance and must not see this switcher.
    $canUseWorkspaceNav = isSubscribed() || ! isCloud();
    $pageDestinations = $canUseWorkspaceNav
        ? collect([
            ['label' => 'Dashboard', 'href' => url('/')],
            ['label' => 'Projects', 'href' => url('/projects')],
            auth()->user()?->can('canAccessTerminal')
                ? ['label' => 'Terminal', 'href' => route('terminal')]
                : null,
            ['label' => 'Servers', 'href' => url('/servers')],
            ['label' => 'Sources', 'href' => route('source.all')],
            ['label' => 'Destinations', 'href' => route('destination.index')],
            ['label' => 'S3 Storage', 'href' => route('storage.index')],
            ['label' => 'Shared Variables', 'href' => route('shared-variables.index')],
            ['label' => 'Team', 'href' => route('team.index')],
            ['label' => 'Notifications', 'href' => route('notifications.email')],
            ['label' => 'Keys & Tokens', 'href' => route('security.private-key.index')],
            ['label' => 'Tags', 'href' => route('tags.show')],
            isInstanceAdmin()
                ? ['label' => 'Settings', 'href' => route('settings.index')]
                : null,
        ])->filter()
        : collect();
@endphp
<div class="flex min-w-0 items-center gap-0.5 text-[13px]">
    {{-- Team --}}
    <div class="shrink-0" x-data="{ collapsed: false }">
        <livewire:switch-team />
    </div>

    @if (!$currentProject && $dashboardContext && $canUseWorkspaceNav)
        <span class="shrink-0 px-0.5 text-neutral-300 dark:text-fg-faint">/</span>
        <div class="relative min-w-0 shrink" x-data="{ open: false }" @keydown.escape.window="open = false">
            <button type="button" @click="open = !open" @click.outside="open = false" title="Switch page"
                class="flex h-8 min-w-0 items-center gap-1.5 rounded-md px-2 opacity-70 transition-[background-color,opacity] hover:bg-neutral-100 hover:opacity-100 dark:hover:bg-white/[0.05]">
                <span class="min-w-0 truncate font-semibold text-black dark:text-fg">{{ $dashboardContext }}</span>
                <svg class="size-4 shrink-0 text-neutral-400 dark:text-fg-faint" viewBox="0 0 24 24"
                    fill="none">
                    <path d="M8 9l4-4 4 4M8 15l4 4 4-4" stroke="currentColor" stroke-width="1.6"
                        stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>
            <div x-show="open" x-cloak x-transition.opacity.duration.120ms
                class="listbox-panel scrollbar left-0! z-[90]! max-h-80! min-w-52">
                <div class="px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-neutral-400 dark:text-fg-faint">
                    Pages
                </div>
                @foreach ($pageDestinations as $destination)
                    <a href="{{ $destination['href'] }}" {{ wireNavigate() }} @click="open = false"
                        class="listbox-option {{ $destination['label'] === $dashboardContext ? 'bg-neutral-100 font-medium text-black dark:bg-white/[0.07] dark:text-fg' : '' }}">
                        <span class="min-w-0 flex-1 truncate">{{ $destination['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @elseif (!$currentProject && $dashboardContext)
        {{-- Static context label only — no links to paid workspace pages. --}}
        <span class="shrink-0 px-0.5 text-neutral-300 dark:text-fg-faint">/</span>
        <span
            class="flex h-8 min-w-0 items-center truncate px-2 font-semibold text-black opacity-70 dark:text-fg">{{ $dashboardContext }}</span>
    @endif

    @if ($currentStorage)
        <x-breadcrumb-switcher title="S3 Storage" :label="$currentStorage->name" :items="$storages->map(fn ($storage) => [
            'label' => $storage->name,
            'href' => route('storage.show', ['storage_uuid' => $storage->uuid]),
            'active' => $storage->uuid === $currentStorage->uuid,
        ])">
            <x-slot:meta>
                <span class="inline-flex h-[22px] shrink-0 items-center gap-1.5 rounded-full border border-neutral-200 bg-neutral-100 px-2.5 text-xs font-medium text-black dark:border-white/[0.12] dark:bg-white/[0.08] dark:text-fg"
                    x-data="{ usable: @js((bool) $currentStorage->is_usable) }"
                    @storage-status-changed.window="usable = $event.detail.isUsable">
                    <span class="size-1.5 rounded-full" :class="usable ? 'bg-[#3fb950]' : 'bg-red-500'"></span>
                    <span x-text="usable ? 'Connected' : 'Not usable'"></span>
                </span>
            </x-slot:meta>
        </x-breadcrumb-switcher>
    @endif

    @if ($currentSource)
        @php
            $sourceConnected = $currentSource instanceof \App\Models\GithubApp
                ? filled($currentSource->installation_id)
                : filled($currentSource->access_token);
        @endphp
        <x-breadcrumb-switcher title="Sources" :label="$currentSource->name ?: 'Source'" :items="$sources->map(fn ($source) => [
            'label' => $source->name ?: class_basename($source),
            'href' => $source instanceof \App\Models\GithubApp
                ? route('source.github.show', ['github_app_uuid' => $source->uuid])
                : route('source.gitlab.show', ['gitlab_app_uuid' => $source->uuid]),
            'active' => $source->getMorphClass() === $currentSource->getMorphClass() && $source->uuid === $currentSource->uuid,
        ])">
            <x-slot:meta>
                <span class="inline-flex h-[22px] shrink-0 items-center gap-1.5 rounded-full border border-neutral-200 bg-neutral-100 px-2.5 text-xs font-medium text-black dark:border-white/[0.12] dark:bg-white/[0.08] dark:text-fg">
                <span @class([
                    'size-1.5 rounded-full',
                    'bg-[#3fb950]' => $sourceConnected,
                    'bg-warning' => ! $sourceConnected,
                ])></span>
                    {{ $sourceConnected ? 'Connected' : 'Setup incomplete' }}
                </span>
            </x-slot:meta>
        </x-breadcrumb-switcher>
    @endif

    @if ($currentDestination)
        <x-breadcrumb-switcher title="Destinations" :label="$currentDestination->name" :items="$destinations->map(fn ($destination) => [
            'label' => $destination->name,
            'href' => route('destination.show', ['destination_uuid' => $destination->uuid]),
            'active' => $destination->getMorphClass() === $currentDestination->getMorphClass() && $destination->uuid === $currentDestination->uuid,
        ])">
            <x-slot:meta>
                <span class="inline-flex h-[22px] shrink-0 items-center gap-1.5 rounded-full border border-neutral-200 bg-neutral-100 px-2.5 text-xs font-medium text-black dark:border-white/[0.12] dark:bg-white/[0.08] dark:text-fg">
                <span @class([
                    'size-1.5 rounded-full',
                    'bg-[#3fb950]' => $currentDestination->getMorphClass() === 'App\\Models\\StandaloneDocker',
                    'bg-warning' => $currentDestination->getMorphClass() !== 'App\\Models\\StandaloneDocker',
                ])></span>
                    {{ $currentDestination->getMorphClass() === 'App\\Models\\StandaloneDocker' ? 'Docker' : 'Deprecated' }}
                </span>
            </x-slot:meta>
        </x-breadcrumb-switcher>
    @endif

    @if ($currentTag)
        <x-breadcrumb-switcher title="Tags" :label="$currentTag->name" :items="collect([[
            'label' => 'All tags',
            'href' => route('tags.show'),
            'active' => false,
        ]])->concat($tags->map(fn ($tag) => [
            'label' => $tag->name,
            'href' => route('tags.show', ['tagName' => $tag->name]),
            'active' => $tag->name === $currentTag->name,
        ]))" />
    @endif

    @if ($currentProject)
        <span class="shrink-0 text-neutral-300 dark:text-fg-faint px-0.5">/</span>
        {{-- Project switcher --}}
        <div class="relative min-w-0 shrink" x-data="{ open: false }" @keydown.escape.window="open = false">
            <button type="button" @click="open = !open" @click.outside="open = false" title="Switch project"
                class="flex items-center gap-1.5 min-w-0 h-8 px-2 rounded-md opacity-70 transition-[background-color,opacity] hover:opacity-100 hover:bg-neutral-100 dark:hover:bg-white/[0.05]">
                <span class="min-w-0 truncate font-semibold text-black dark:text-fg">{{ $currentProject->name }}</span>
                <svg class="size-4 shrink-0 text-neutral-400 dark:text-fg-faint" viewBox="0 0 24 24" fill="none">
                    <path d="M8 9l4-4 4 4M8 15l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>
            <div x-show="open" x-cloak x-transition.opacity.duration.120ms
                class="listbox-panel scrollbar left-0! z-[90]! max-h-80! min-w-56 max-w-72">
                <div class="px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-neutral-400 dark:text-fg-faint">
                    Projects
                </div>
                @foreach ($projects as $p)
                    <a href="{{ route($projectDestinationRoute, ['project_uuid' => $p->uuid]) }}" {{ wireNavigate() }} @click="open = false"
                        class="listbox-option {{ $p->uuid === $currentProject->uuid ? 'bg-neutral-100 font-medium text-black dark:bg-white/[0.07] dark:text-fg' : '' }}">
                        <span class="min-w-0 flex-1 truncate">{{ $p->name }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    @if ($currentProject && $currentEnvironment)
        <span class="shrink-0 text-neutral-300 dark:text-fg-faint px-0.5">/</span>
        {{-- Environment switcher --}}
        <div class="relative min-w-0 shrink" x-data="{ open: false }" @keydown.escape.window="open = false">
            <button type="button" @click="open = !open" @click.outside="open = false" title="Switch environment"
                class="flex items-center gap-1.5 min-w-0 h-8 px-2 rounded-md opacity-70 transition-[background-color,opacity] hover:opacity-100 hover:bg-neutral-100 dark:hover:bg-white/[0.05]">
                <span class="min-w-0 truncate font-semibold text-black dark:text-fg">{{ $currentEnvironment->name }}</span>
                <svg class="size-4 shrink-0 text-neutral-400 dark:text-fg-faint" viewBox="0 0 24 24" fill="none">
                    <path d="M8 9l4-4 4 4M8 15l4 4 4-4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </button>
            <div x-show="open" x-cloak x-transition.opacity.duration.120ms
                class="listbox-panel scrollbar left-0! z-[90]! max-h-80! min-w-52 max-w-72">
                <div class="px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-neutral-400 dark:text-fg-faint">
                    Environments
                </div>
                @foreach ($environments as $env)
                    <a href="{{ route('project.resource.index', ['project_uuid' => $currentProject->uuid, 'environment_uuid' => $env->uuid]) }}" {{ wireNavigate() }} @click="open = false"
                        class="listbox-option {{ $env->uuid === $currentEnvironment->uuid ? 'bg-neutral-100 font-medium text-black dark:bg-white/[0.07] dark:text-fg' : '' }}">
                        <span class="min-w-0 flex-1 truncate">{{ $env->name }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    @if ($currentResource)
        <x-breadcrumb-switcher title="Resources" :label="$currentResource->name" :items="$resourceItems">
            <x-slot:meta>
                @if ($currentApplication)
                    <livewire:project.application.status :application="$currentApplication"
                        :wire:key="'application-status-'.$currentApplication->uuid" />
                @elseif ($currentDatabase)
                    <livewire:project.database.status :database="$currentDatabase"
                        :wire:key="'database-status-'.$currentDatabase->uuid" />
                @else
                    <livewire:project.service.status :service="$currentService"
                        :wire:key="'service-status-'.$currentService->uuid" />
                @endif
            </x-slot:meta>
        </x-breadcrumb-switcher>
    @endif
</div>
