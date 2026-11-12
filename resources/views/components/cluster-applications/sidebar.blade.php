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
                ['key' => 'environment-variables', 'label' => 'Environment Variables', 'icon' => 'variables'],
                ['key' => 'storage', 'label' => 'Persistent Storage', 'icon' => 'storages'],
                ['key' => 'healthcheck', 'label' => 'Healthcheck', 'icon' => 'feedback'],
            ],
            'Observe & troubleshoot' => [
                ['key' => 'logs', 'label' => 'Runtime Logs', 'icon' => 'unordered-list'],
                // Not in the v4 database menu: on a cluster, a database deploys like an application.
                ['key' => 'deployments', 'label' => 'Deployment Logs', 'icon' => 'time-back'],
                ['label' => 'Terminal', 'icon' => 'browser-terminal', 'visible' => $canAccessTerminal],
                ['label' => 'Files', 'icon' => 'folder', 'visible' => $canAccessTerminal],
                ['label' => 'Metrics', 'icon' => 'graph'],
                ['label' => 'Security', 'icon' => 'shield-alert'],
            ],
            'Deploy' => [
                ['key' => 'servers', 'label' => 'Servers', 'icon' => 'servers'],
            ],
            'Automation' => [
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
                ['key' => 'environment-variables', 'label' => 'Environment Variables', 'icon' => 'variables'],
                ['key' => 'storage', 'label' => 'Persistent Storage', 'icon' => 'storages'],
                ['label' => 'Advanced', 'icon' => 'grid'],
                ['key' => 'healthcheck', 'label' => 'Healthcheck', 'icon' => 'feedback'],
            ],
            'Observe & troubleshoot' => [
                ['key' => 'logs', 'label' => 'Runtime Logs', 'icon' => 'unordered-list'],
                ['key' => 'deployments', 'label' => 'Deployment Logs', 'icon' => 'time-back'],
                ['label' => 'Terminal', 'icon' => 'browser-terminal', 'visible' => $canAccessTerminal],
                ['label' => 'Files', 'icon' => 'folder', 'visible' => $canAccessTerminal],
                ['label' => 'Metrics', 'icon' => 'graph'],
                ['label' => 'Analytics', 'icon' => 'analytics'],
                ['label' => 'Security', 'icon' => 'shield-alert'],
            ],
            'Deploy' => [
                ['label' => 'Git Source', 'icon' => 'sources', 'visible' => $workload->buildsFromSource()],
                ['key' => 'servers', 'label' => 'Servers', 'icon' => 'servers'],
                ['label' => 'Preview Deployments', 'icon' => 'eye'],
            ],
            'Automation' => [
                ['label' => 'Scheduled Tasks', 'icon' => 'calendar'],
                ['key' => 'webhooks', 'label' => 'Webhooks', 'icon' => 'webhook'],
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
        'environment-variables' => 'project.cluster-application.environment-variables',
        'storage' => 'project.cluster-application.storage',
        'healthcheck' => 'project.cluster-application.healthcheck',
        'logs' => 'project.cluster-application.logs',
        'deployments' => 'project.cluster-application.deployments',
        'servers' => 'project.cluster-application.servers',
        'resource-limits' => 'project.cluster-application.resource-limits',
        'webhooks' => 'project.cluster-application.webhooks',
        'backups' => 'project.cluster-application.backups',
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
@endphp

<x-main-nav-menu back-label="Dashboard" :name="$workload->name">
    <nav aria-label="{{ $workload->isDatabase() ? 'Cluster database sections' : 'Cluster application sections' }}"
        class="grid grid-cols-1 gap-0.5">
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
