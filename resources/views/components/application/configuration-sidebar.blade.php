@props(['application', 'currentRoute', 'flush' => false, 'navOnly' => false])

@php
        $applicationRouteParameters = [
            'project_uuid' => $application->environment->project->uuid,
            'environment_uuid' => $application->environment->uuid,
            'application_uuid' => $application->uuid,
        ];

        $configurationMenuItems = [
            [
                'label' => 'General',
                'route' => 'project.application.configuration',
                'active' => $currentRoute === 'project.application.configuration',
            ],
            [
                'label' => 'Domains',
                'route' => 'project.application.domains',
                'active' => $currentRoute === 'project.application.domains',
            ],
            [
                'label' => 'Advanced',
                'route' => 'project.application.advanced',
                'active' => $currentRoute === 'project.application.advanced',
            ],
            [
                'label' => 'Swarm',
                'route' => 'project.application.swarm',
                'active' => $currentRoute === 'project.application.swarm',
                'visible' => $application->destination->server->isSwarm(),
            ],
            [
                'label' => 'Environment Variables',
                'route' => 'project.application.environment-variables',
                'active' => $currentRoute === 'project.application.environment-variables',
            ],
            [
                'label' => 'Persistent Storage',
                'route' => 'project.application.persistent-storage',
                'active' => $currentRoute === 'project.application.persistent-storage',
            ],
            [
                'label' => 'Backups',
                'route' => 'project.application.backup.index',
                'active' => str($currentRoute)->startsWith('project.application.backup'),
            ],
            [
                'label' => 'Terminal',
                'route' => 'project.application.command',
                'active' => $currentRoute === 'project.application.command',
                'navigate' => false,
                'visible' => ! $application->destination->server->isSwarm() && auth()->user()?->can('canAccessTerminal'),
            ],
            [
                'label' => 'Files',
                'route' => 'project.application.files',
                'active' => $currentRoute === 'project.application.files',
                'visible' => ! $application->destination->server->isSwarm() && auth()->user()?->can('canAccessTerminal'),
            ],
            [
                'label' => 'Deployment Logs',
                'route' => 'project.application.deployment.index',
                'active' => str($currentRoute)->startsWith('project.application.deployment'),
            ],
            [
                'label' => 'Runtime Logs',
                'route' => 'project.application.logs',
                'active' => $currentRoute === 'project.application.logs',
            ],
            [
                'label' => 'Git Source',
                'route' => 'project.application.source',
                'active' => $currentRoute === 'project.application.source',
                'visible' => $application->git_based(),
            ],
            [
                'label' => 'Servers',
                'route' => 'project.application.servers',
                'active' => $currentRoute === 'project.application.servers',
                'badge' => true,
            ],
            [
                'label' => 'Scheduled Tasks',
                'route' => 'project.application.scheduled-tasks.show',
                'active' => str($currentRoute)->startsWith('project.application.scheduled-tasks'),
            ],
            [
                'label' => 'Webhooks',
                'route' => 'project.application.webhooks',
                'active' => $currentRoute === 'project.application.webhooks',
            ],
            [
                'label' => 'Preview Deployments',
                'route' => 'project.application.preview-deployments',
                'active' => $currentRoute === 'project.application.preview-deployments',
                'visible' => $application->git_based() || $application->build_pack === 'dockerimage',
            ],
            [
                'label' => 'Healthcheck',
                'route' => 'project.application.healthcheck',
                'active' => $currentRoute === 'project.application.healthcheck',
                'visible' => $application->build_pack !== 'dockercompose',
            ],
            [
                'label' => 'Rollback',
                'route' => 'project.application.rollback',
                'active' => $currentRoute === 'project.application.rollback',
            ],
            [
                'label' => 'Resource Limits',
                'route' => 'project.application.resource-limits',
                'active' => $currentRoute === 'project.application.resource-limits',
            ],
            [
                'label' => 'Resource Operations',
                'route' => 'project.application.resource-operations',
                'active' => $currentRoute === 'project.application.resource-operations',
            ],
            [
                'label' => 'Metrics',
                'route' => 'project.application.metrics',
                'active' => $currentRoute === 'project.application.metrics',
            ],
            [
                'label' => 'Analytics',
                'route' => 'project.application.analytics',
                'active' => $currentRoute === 'project.application.analytics',
            ],
            [
                'label' => 'Tags',
                'route' => 'project.application.tags',
                'active' => $currentRoute === 'project.application.tags',
            ],
            [
                'label' => 'Danger Zone',
                'route' => 'project.application.danger',
                'active' => $currentRoute === 'project.application.danger',
            ],
        ];

        $configurationMenuItems = array_values(array_filter(
            $configurationMenuItems,
            fn (array $item): bool => $item['visible'] ?? true,
        ));

        // Icons follow the main sidebar's reicon set
        $menuIcons = [
            'General' => 'settings',
            'Domains' => 'globe',
            'Advanced' => 'grid',
            'Swarm' => 'destinations',
            'Environment Variables' => 'variables',
            'Persistent Storage' => 'storages',
            'Backups' => 'database',
            'Terminal' => 'browser-terminal',
            'Files' => 'folder',
            'Deployment Logs' => 'time-back',
            'Runtime Logs' => 'unordered-list',
            'Git Source' => 'sources',
            'Servers' => 'servers',
            'Scheduled Tasks' => 'calendar',
            'Webhooks' => 'webhook',
            'Preview Deployments' => 'eye',
            'Healthcheck' => 'feedback',
            'Rollback' => 'time-back',
            'Resource Limits' => 'cpu',
            'Resource Operations' => 'server-update',
            'Metrics' => 'graph',
            'Analytics' => 'analytics',
            'Tags' => 'tags',
            'Danger Zone' => 'danger',
        ];

        // Discord-style groups for the settings sidebar
        $menuGroups = [
            'Settings' => ['General', 'Domains', 'Environment Variables', 'Persistent Storage', 'Advanced', 'Swarm', 'Healthcheck'],
            'Observe & troubleshoot' => ['Runtime Logs', 'Deployment Logs', 'Terminal', 'Files', 'Metrics', 'Analytics'],
            'Deploy' => ['Git Source', 'Servers', 'Preview Deployments'],
            'Automation' => ['Scheduled Tasks', 'Webhooks', 'Backups'],
            'Operations' => ['Resource Operations', 'Resource Limits', 'Rollback', 'Tags'],
            'Danger Zone' => ['Danger Zone'],
        ];
        $groupedMenuItems = collect($menuGroups)
            ->map(fn (array $labels) => collect($labels)
                ->map(fn (string $label) => collect($configurationMenuItems)->firstWhere('label', $label))
                ->filter()
                ->values())
            ->filter(fn ($items) => $items->isNotEmpty());

        // In-page sections (cards) shown as sub-items under the active page
        $isComposeApp = $application->build_pack === 'dockercompose';
        $pageSections = [
            'project.application.configuration' => array_values(array_filter([
                ['id' => 'application-details-section', 'label' => 'Application details'],
                ['id' => 'access-section', 'label' => 'Access'],
                ['id' => 'build-pipeline-section', 'label' => 'Build & deploy'],
                $isComposeApp ? null : ['id' => 'container-section', 'label' => 'Container'],
            ])),
            'project.application.advanced' => [
                ['id' => 'advanced-build-section', 'label' => 'Build & deploy'],
                ['id' => 'advanced-container-section', 'label' => 'Container'],
                ['id' => 'advanced-proxy-section', 'label' => 'Proxy'],
            ],
            'project.application.webhooks' => [
                ['id' => 'deploy-webhook-section', 'label' => 'Deploy webhook'],
                ['id' => 'manual-git-webhooks-section', 'label' => 'Manual Git webhooks'],
            ],
            'project.application.preview-deployments' => array_values(array_filter([
                ['id' => 'preview-template-section', 'label' => 'URL template'],
                $application->build_pack === 'dockerimage'
                    ? ['id' => 'manual-preview-section', 'label' => 'Manual preview']
                    : null,
                ['id' => 'preview-deployments-section', 'label' => 'Deployments'],
            ])),
            'project.application.healthcheck' => [
                ['id' => 'healthcheck-configuration-section', 'label' => 'Configuration'],
                ['id' => 'healthcheck-timing-section', 'label' => 'Timing and retries'],
            ],
            'project.application.resource-operations' => [
                ['id' => 'clone-resource-section', 'label' => 'Clone resource'],
                ['id' => 'move-resource-section', 'label' => 'Move resource'],
            ],
        ];

        // Pages and in-page sections that the command palette (⌘K) can search on this page
        $settingsSearchItems = settingsSearchItems($groupedMenuItems, $applicationRouteParameters, $pageSections);
    @endphp

@php
    // The main sidebar and the mobile drawer show this menu (see components/navbar.blade.php).
    registerMainNavContext(0, 'Dashboard', 'Back to the main menu');
@endphp

{{-- Rendered twice: in the page (CSS hides it; it keeps the command palette items), and teleported into the main sidebar.
     navOnly renders only the sidebar copy, as the parent menu of a deeper page (e.g. a backup). --}}
@foreach ($navOnly ? ['main-nav'] : ['page', 'main-nav'] as $placement)
@if ($placement === 'main-nav')
@teleport('#main-nav-context-0')
<div class="nav-context-menu">
@else
<aside @class([
    'application-settings-navigation in-main-nav min-w-0',
    'is-flush' => $flush,
]) data-settings-search-items="{{ json_encode($settingsSearchItems) }}">
@endif
                <nav aria-label="Configuration sections"
                    class="grid grid-cols-1 gap-0.5">
                    @foreach ($groupedMenuItems as $groupLabel => $groupItems)
                        @unless ($loop->first)
                            <div class="my-2 border-t border-neutral-200 dark:border-white/[0.06]"
                                aria-hidden="true"></div>
                        @endunless
                        @foreach ($groupItems as $menuItem)
                            @php $sections = $pageSections[$menuItem['route']] ?? []; @endphp
                            <div wire:key="{{ $placement }}-application-settings-group-{{ str($menuItem['label'])->slug() }}">
                                <a wire:key="{{ $placement }}-application-settings-link-{{ str($menuItem['label'])->slug() }}"
                                    @class([
                                        'menu-item',
                                        'menu-item-active' => $menuItem['active'],
                                    ])
                            @if ($menuItem['navigate'] ?? true) {{ wireNavigate() }} @endif
                                    href="{{ route($menuItem['route'], $applicationRouteParameters) }}"
                                    >
                                    <x-reicon :name="$menuIcons[$menuItem['label']] ?? 'settings'" class="menu-item-icon" />
                                    <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                                    @if ($menuItem['label'] === 'Files')
                                        <x-beta-badge class="ml-auto shrink-0" />
                                    @endif
                                    @if ($menuItem['badge'] ?? false)
                                        <span class="shrink-0">
                                            <livewire:project.application.server-status-badge :application="$application"
                                                :key="$placement.'-application-server-status-'.$application->uuid" />
                                        </span>
                                    @endif
                                </a>
                                {{-- Sub-sections belong to the current page only; collapse them for
                                     every other item so the sidebar stays short. --}}
                                @if ($menuItem['active'] && filled($sections))
                                    <div class="nav-children flex flex-col gap-0.5 py-1"
                                        x-data="{ activeSection: '' }">
                                        @foreach ($sections as $section)
                                            <button type="button" class="menu-subitem"
                                                :class="activeSection === '{{ $section['id'] }}' && 'menu-subitem-active'"
                                                x-on:click="activeSection = '{{ $section['id'] }}'; history.replaceState(null, '', '#{{ $section['id'] }}'); window.scrollToSettingsSection?.('{{ $section['id'] }}')">
                                                <span class="menu-item-label text-left">{{ $section['label'] }}</span>
                                            </button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    @endforeach
                </nav>
            @if ($placement === 'main-nav')
</div>
@endteleport
@else
</aside>
@endif
@endforeach
