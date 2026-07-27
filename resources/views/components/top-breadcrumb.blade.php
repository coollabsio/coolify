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
    $pageDestinations = collect([
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
    ])->filter();
@endphp
<div class="flex items-center gap-0.5 min-w-0 text-[13px]">
    {{-- Team --}}
    <div class="shrink-0" x-data="{ collapsed: false }">
        <livewire:switch-team />
    </div>

    @if (!$currentProject && $dashboardContext)
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
                @foreach ($pageDestinations as $destination)
                    <a href="{{ $destination['href'] }}" {{ wireNavigate() }} @click="open = false"
                        class="listbox-option {{ $destination['label'] === $dashboardContext ? 'bg-neutral-100 font-medium text-black dark:bg-white/[0.07] dark:text-fg' : '' }}">
                        <span class="min-w-0 flex-1 truncate">{{ $destination['label'] }}</span>
                        @if ($destination['label'] === $dashboardContext)
                            <svg class="size-3.5 shrink-0 text-coollabs dark:text-warning" viewBox="0 0 24 24"
                                fill="none">
                                <path d="M5 12l5 5 9-11" stroke="currentColor" stroke-width="2"
                                    stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
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
                @foreach ($projects as $p)
                    <a href="{{ route($projectDestinationRoute, ['project_uuid' => $p->uuid]) }}" {{ wireNavigate() }} @click="open = false"
                        class="listbox-option {{ $p->uuid === $currentProject->uuid ? 'bg-neutral-100 font-medium text-black dark:bg-white/[0.07] dark:text-fg' : '' }}">
                        <span class="min-w-0 flex-1 truncate">{{ $p->name }}</span>
                        @if ($p->uuid === $currentProject->uuid)
                            <svg class="size-3.5 shrink-0 text-coollabs dark:text-warning" viewBox="0 0 24 24" fill="none"><path d="M5 12l5 5 9-11" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                        @endif
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
                @foreach ($environments as $env)
                    <a href="{{ route('project.resource.index', ['project_uuid' => $currentProject->uuid, 'environment_uuid' => $env->uuid]) }}" {{ wireNavigate() }} @click="open = false"
                        class="listbox-option {{ $env->uuid === $currentEnvironment->uuid ? 'bg-neutral-100 font-medium text-black dark:bg-white/[0.07] dark:text-fg' : '' }}">
                        <span class="min-w-0 flex-1 truncate">{{ $env->name }}</span>
                        @if ($env->uuid === $currentEnvironment->uuid)
                            <svg class="size-3.5 shrink-0 text-coollabs dark:text-warning" viewBox="0 0 24 24" fill="none"><path d="M5 12l5 5 9-11" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    @if ($currentApplication)
        @php
            $applicationStatus = str($currentApplication->status ?? 'exited');
            [$statusDotClass, $statusLabel] = match (true) {
                $applicationStatus->startsWith('running') => ['bg-[#3fb950]', 'Running'],
                $applicationStatus->startsWith('degraded') => ['bg-orange-400', 'Degraded'],
                $applicationStatus->startsWith('restarting'),
                $applicationStatus->startsWith('starting') => ['bg-warning', 'Restarting'],
                default => ['bg-neutral-400 dark:bg-fg-faint', 'Stopped'],
            };
        @endphp
        <span class="shrink-0 text-neutral-300 dark:text-fg-faint px-0.5">/</span>
        <span class="flex min-w-0 shrink items-center gap-2 h-8 px-2">
            <span class="min-w-0 truncate font-semibold text-black dark:text-fg">{{ $currentApplication->name }}</span>
            <span class="inline-flex h-[22px] shrink-0 items-center gap-1.5 rounded-full bg-neutral-100 px-2.5 text-xs font-medium text-black dark:bg-white/[0.08] dark:text-fg"
                title="{{ $currentApplication->status }}">
                <span class="size-1.5 rounded-full {{ $statusDotClass }}"></span>
                {{ $statusLabel }}
            </span>
        </span>
    @endif
</div>
