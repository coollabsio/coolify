@props(['section', 'routeParameters', 'workload'])

@php
    $activeKey = in_array($section, ['deployment', 'build'], true) ? 'deployments' : $section;
    $canAccessTerminal = auth()->user()?->can('canAccessTerminal') ?? false;
    // Volume backups (decision 0009) need a volume to back up.
    $hasVolumes = $workload->volumes()->whereNull('detached_at')->exists();

    // The v4 menu of the same resource type. An item without a `key` is a v4 page that clusters
    // do not have yet: it stays in the menu, disabled, so both menus keep the same shape.
    $menuGroups = $workload->isDatabase()
        ? [
            'Settings' => [
                ['key' => 'general', 'label' => 'General', 'icon' => 'settings'],
                ['key' => 'ports', 'label' => 'Published Ports', 'icon' => 'network'],
                ['key' => 'environment-variables', 'label' => 'Environment Variables', 'icon' => 'variables'],
                ['key' => 'storage', 'label' => 'Persistent Storage', 'icon' => 'storages'],
                ['key' => 'healthcheck', 'label' => 'Healthcheck', 'icon' => 'feedback'],
            ],
            'Observe & troubleshoot' => [
                ['key' => 'logs', 'label' => 'Runtime Logs', 'icon' => 'unordered-list'],
                // Not in the v4 database menu: on a cluster, a database deploys like an application.
                ['key' => 'deployments', 'label' => 'Deployment Logs', 'icon' => 'time-back'],
                ['key' => 'terminal', 'label' => 'Terminal', 'icon' => 'browser-terminal', 'visible' => $canAccessTerminal],
                ['label' => 'Files', 'icon' => 'folder', 'visible' => $canAccessTerminal],
                ['label' => 'Metrics', 'icon' => 'graph'],
                ['label' => 'Security', 'icon' => 'shield-alert'],
            ],
            'Deploy' => [
                ['key' => 'servers', 'label' => 'Servers', 'icon' => 'servers'],
            ],
            'Automation' => [
                ['key' => 'scheduled-tasks', 'label' => 'Scheduled Tasks', 'icon' => 'calendar'],
                ['key' => 'webhooks', 'label' => 'Webhooks', 'icon' => 'webhook'],
                ['key' => $hasVolumes ? 'backups' : null, 'label' => 'Backups', 'icon' => 'database'],
            ],
            'Operations' => [
                ['label' => 'Resource Operations', 'icon' => 'server-update'],
                ['key' => 'resource-limits', 'label' => 'Resource Limits', 'icon' => 'cpu'],
                ['label' => 'Tags', 'icon' => 'tags'],
            ],
            'Danger Zone' => [
                ['key' => 'danger', 'label' => 'Danger Zone', 'icon' => 'danger'],
            ],
        ]
        : [
            'Settings' => [
                ['key' => 'general', 'label' => 'General', 'icon' => 'settings'],
                ['key' => 'domains', 'label' => 'Domains', 'icon' => 'globe'],
                ['key' => 'ports', 'label' => 'Published Ports', 'icon' => 'network'],
                ['key' => 'environment-variables', 'label' => 'Environment Variables', 'icon' => 'variables'],
                ['key' => 'storage', 'label' => 'Persistent Storage', 'icon' => 'storages'],
                ['label' => 'Advanced', 'icon' => 'grid'],
                ['key' => 'healthcheck', 'label' => 'Healthcheck', 'icon' => 'feedback'],
            ],
            'Observe & troubleshoot' => [
                ['key' => 'logs', 'label' => 'Runtime Logs', 'icon' => 'unordered-list'],
                ['key' => 'deployments', 'label' => 'Deployment Logs', 'icon' => 'time-back'],
                ['key' => 'terminal', 'label' => 'Terminal', 'icon' => 'browser-terminal', 'visible' => $canAccessTerminal],
                ['label' => 'Files', 'icon' => 'folder', 'visible' => $canAccessTerminal],
                ['label' => 'Metrics', 'icon' => 'graph'],
                ['label' => 'Analytics', 'icon' => 'analytics'],
                ['label' => 'Security', 'icon' => 'shield-alert'],
            ],
            'Deploy' => [
                ['label' => 'Git Source', 'icon' => 'sources', 'visible' => $workload->buildsFromGit()],
                ['key' => 'servers', 'label' => 'Servers', 'icon' => 'servers'],
                ['key' => 'previews', 'label' => 'Previews', 'icon' => 'eye', 'visible' => $workload->canHavePreviews()],
            ],
            'Automation' => [
                ['key' => 'scheduled-tasks', 'label' => 'Scheduled Tasks', 'icon' => 'calendar'],
                // A preview is not a webhook target (decision 0025).
                ['key' => 'webhooks', 'label' => 'Webhooks', 'icon' => 'webhook', 'visible' => ! $workload->isPreview()],
                ['key' => $hasVolumes ? 'backups' : null, 'label' => 'Backups', 'icon' => 'database'],
            ],
            'Operations' => [
                ['label' => 'Resource Operations', 'icon' => 'server-update'],
                ['label' => 'Maintenance Mode', 'icon' => 'bandage'],
                ['key' => 'resource-limits', 'label' => 'Resource Limits', 'icon' => 'cpu'],
                ['label' => 'Rollback', 'icon' => 'time-back'],
                ['label' => 'Tags', 'icon' => 'tags'],
            ],
            'Danger Zone' => [
                ['key' => 'danger', 'label' => 'Danger Zone', 'icon' => 'danger'],
            ],
        ];

    $sectionRoutes = [
        'general' => 'project.cluster-application.show',
        'domains' => 'project.cluster-application.domains',
        'ports' => 'project.cluster-application.ports',
        'environment-variables' => 'project.cluster-application.environment-variables',
        'storage' => 'project.cluster-application.storage',
        'healthcheck' => 'project.cluster-application.healthcheck',
        'logs' => 'project.cluster-application.logs',
        'deployments' => 'project.cluster-application.deployments',
        'servers' => 'project.cluster-application.servers',
        'previews' => 'project.cluster-application.previews',
        'resource-limits' => 'project.cluster-application.resource-limits',
        'webhooks' => 'project.cluster-application.webhooks',
        'backups' => 'project.cluster-application.backups',
        'scheduled-tasks' => 'project.cluster-application.scheduled-tasks',
        'terminal' => 'project.cluster-application.terminal',
        'danger' => 'project.cluster-application.danger',
    ];

    $groupedMenuItems = collect($menuGroups)
        ->map(fn (array $items) => collect($items)->filter(fn (array $item): bool => $item['visible'] ?? true)->values())
        ->filter(fn ($items) => $items->isNotEmpty());

    // In-page sections (cards) shown as sub-items under the active page, like the v4 sidebar.
    $pageSections = [
        'general' => $workload->isDatabase()
            ? [
                ['id' => 'database-details-section', 'label' => 'Database details'],
                ['id' => 'access-section', 'label' => 'Access'],
            ]
            : [
                ['id' => 'cluster-application-details', 'label' => 'Application details'],
                ['id' => 'cluster-application-access', 'label' => 'Access'],
                ['id' => 'cluster-application-build', 'label' => 'Build & deploy'],
                ['id' => 'cluster-application-container', 'label' => 'Container'],
            ],
        'resource-limits' => [
            ['id' => 'cluster-application-cpu-limits', 'label' => 'CPU'],
            ['id' => 'cluster-application-memory-limits', 'label' => 'Memory'],
        ],
    ];
    $settingsSearchItems = settingsSearchItems(
        $groupedMenuItems->map(fn ($items) => $items->filter(fn (array $item): bool => isset($item['key']))
            ->map(fn (array $item): array => [...$item, 'route' => $sectionRoutes[$item['key']]])),
        $routeParameters,
        collect($pageSections)->mapWithKeys(fn (array $sections, string $key): array => [$sectionRoutes[$key] => $sections])->all(),
    );
@endphp

@php
    // A service of a stack is one level below its stack, like a resource of a v4 service.
    $stack = $workload->node_stack_id === null ? null : $workload->stack;
    $stackRouteParameters = $stack === null ? [] : ['project_uuid' => $routeParameters['project_uuid'], 'environment_uuid' => $routeParameters['environment_uuid'], 'stack_uuid' => $stack->uuid];
@endphp
@if ($stack !== null)
    <x-cluster-stacks.sidebar section="" :route-parameters="$stackRouteParameters" :stack="$stack" nav-only />
@endif
<x-main-nav-menu :depth="$stack === null ? 0 : 1" :back-label="$stack === null ? 'Dashboard' : 'Stack'"
    :back-title="$stack === null ? 'Back to the main menu' : 'Back to the stack menu'" :name="$workload->name">
    <nav aria-label="{{ $workload->isDatabase() ? 'Cluster database sections' : 'Cluster application sections' }}"
        class="grid grid-cols-1 gap-0.5">
        @if ($stack !== null)
            <a class="menu-item" data-page-only {{ wireNavigate() }} href="{{ route('project.cluster-stack.show', $stackRouteParameters) }}">
                <x-reicon name="logout" class="menu-item-icon rotate-180" />
                <span class="menu-item-label">Back to stack</span>
            </a>
            <div class="my-2 border-t border-neutral-200 dark:border-white/[0.06]" aria-hidden="true"></div>
        @endif
        <div hidden data-settings-search-items="{{ json_encode($settingsSearchItems) }}"></div>
        @foreach ($groupedMenuItems as $groupLabel => $groupItems)
            @unless ($loop->first)
                <div class="my-2 border-t border-neutral-200 dark:border-white/[0.06]" aria-hidden="true"></div>
            @endunless
            @foreach ($groupItems as $menuItem)
                @php $key = $menuItem['key'] ?? null; @endphp
                @if ($key === null)
                    <span class="menu-item cursor-not-allowed opacity-50" aria-disabled="true"
                        title="Not available on clusters yet">
                        <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                        <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                        @if ($menuItem['label'] === 'Files')
                            <x-beta-badge class="ml-auto shrink-0" />
                        @endif
                    </span>
                @else
                    <a @class([
                            'menu-item',
                            'menu-item-active' => $key === $activeKey,
                        ])
                        @if ($key === $activeKey) aria-current="page" @endif
                        {{ wireNavigate() }}
                        href="{{ route($sectionRoutes[$key], $routeParameters) }}">
                        <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                        <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                    </a>
                    @if ($key === $section && filled($pageSections[$section] ?? null))
                        <div class="nav-children flex flex-col gap-0.5 py-1" x-data="{ activeSection: '' }">
                            @foreach ($pageSections[$section] as $pageSection)
                                <button type="button" class="menu-subitem"
                                    :class="activeSection === '{{ $pageSection['id'] }}' && 'menu-subitem-active'"
                                    x-on:click="activeSection = '{{ $pageSection['id'] }}'; history.replaceState(null, '', '#{{ $pageSection['id'] }}'); window.scrollToSettingsSection?.('{{ $pageSection['id'] }}')">
                                    <span class="menu-item-label text-left">{{ $pageSection['label'] }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                @endif
            @endforeach
        @endforeach
    </nav>
</x-main-nav-menu>
