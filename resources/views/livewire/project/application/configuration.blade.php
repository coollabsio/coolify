<div>
    <x-slot:title>
        {{ data_get_str($application, 'name')->limit(10) }} > Configuration | Coolify
    </x-slot>
    <livewire:project.shared.configuration-checker :resource="$application" />
    <livewire:project.application.heading :application="$application" />

    @php
        $applicationRouteParameters = [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'application_uuid' => $application->uuid,
        ];

        $configurationMenuItems = [
            [
                'label' => 'General',
                'route' => 'project.application.configuration',
                'active' => $currentRoute === 'project.application.configuration',
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
            'Advanced' => 'grid',
            'Swarm' => 'destinations',
            'Environment Variables' => 'variables',
            'Persistent Storage' => 'storages',
            'Git Source' => 'sources',
            'Servers' => 'servers',
            'Scheduled Tasks' => 'terminal',
            'Webhooks' => 'notifications',
            'Preview Deployments' => 'eye',
            'Healthcheck' => 'feedback',
            'Rollback' => 'logout',
            'Resource Limits' => 'subscription',
            'Resource Operations' => 'teams',
            'Metrics' => 'dashboard',
            'Tags' => 'tags',
            'Danger Zone' => 'admin',
        ];

        // Discord-style groups for the settings sidebar
        $menuGroups = [
            'Settings' => ['General', 'Advanced', 'Swarm', 'Environment Variables', 'Persistent Storage'],
            'Build & deploy' => ['Git Source', 'Servers', 'Preview Deployments', 'Rollback', 'Scheduled Tasks', 'Webhooks', 'Healthcheck'],
            'Operations' => ['Resource Limits', 'Resource Operations', 'Metrics', 'Tags', 'Danger Zone'],
        ];
        $groupedMenuItems = collect($menuGroups)
            ->map(fn (array $labels) => collect($configurationMenuItems)->whereIn('label', $labels)->values())
            ->filter(fn ($items) => $items->isNotEmpty());

        // In-page sections (cards) shown as sub-items under the active page
        $isComposeApp = $application->build_pack === 'dockercompose';
        $pageSections = [
            'project.application.configuration' => array_values(array_filter([
                ['id' => 'application-details-section', 'label' => 'Application details'],
                ['id' => 'public-access-section', 'label' => 'Public access'],
                ['id' => 'build-pipeline-section', 'label' => 'Build pipeline'],
                $isComposeApp ? null : ['id' => 'container-image-section', 'label' => 'Container image'],
                $isComposeApp ? null : ['id' => 'networking-section', 'label' => 'Networking'],
                $isComposeApp ? null : ['id' => 'runtime-section', 'label' => 'Runtime'],
                $isComposeApp ? null : ['id' => 'security-section', 'label' => 'Security'],
                ['id' => 'deployment-lifecycle-section', 'label' => 'Deployment lifecycle'],
                $isComposeApp ? null : ['id' => 'container-labels-section', 'label' => 'Container labels'],
            ])),
            'project.application.advanced' => array_values(array_filter([
                ['id' => 'advanced-build-section', 'label' => 'Build'],
                ['id' => 'advanced-container-section', 'label' => 'Container'],
                $application->git_based() ? ['id' => 'advanced-deployment-section', 'label' => 'Deployment'] : null,
                $application->git_based() ? ['id' => 'advanced-git-section', 'label' => 'Git'] : null,
                $isComposeApp ? ['id' => 'advanced-compose-section', 'label' => 'Docker compose'] : null,
                ['id' => 'advanced-proxy-section', 'label' => 'Proxy'],
                ['id' => 'advanced-operations-section', 'label' => 'Operations'],
                ['id' => 'advanced-logs-section', 'label' => 'Logs'],
                $isComposeApp ? null : ['id' => 'advanced-gpu-section', 'label' => 'GPU'],
            ])),
            'project.application.webhooks' => [
                ['id' => 'deploy-webhook-section', 'label' => 'Deploy webhook'],
                ['id' => 'manual-git-webhooks-section', 'label' => 'Manual Git webhooks'],
            ],
            'project.application.preview-deployments' => array_values(array_filter([
                ['id' => 'preview-template-section', 'label' => 'URL template'],
                $application->is_github_based()
                    ? ['id' => 'preview-pull-requests-section', 'label' => 'Pull requests']
                    : null,
                $application->build_pack === 'dockerimage'
                    ? ['id' => 'manual-preview-section', 'label' => 'Manual preview']
                    : null,
                ['id' => 'preview-deployments-section', 'label' => 'Deployments'],
            ])),
            'project.application.healthcheck' => [
                ['id' => 'healthcheck-configuration-section', 'label' => 'Configuration'],
                [
                    'id' => $application->health_check_type === 'cmd'
                        ? 'healthcheck-command-section'
                        : 'healthcheck-request-section',
                    'label' => $application->health_check_type === 'cmd' ? 'Command' : 'HTTP request',
                ],
                ['id' => 'healthcheck-timing-section', 'label' => 'Timing and retries'],
            ],
            'project.application.rollback' => [
                ['id' => 'rollback-retention-section', 'label' => 'Image retention'],
                ['id' => 'rollback-images-section', 'label' => 'Available images'],
            ],
            'project.application.resource-limits' => [
                ['id' => 'cpu-limits-section', 'label' => 'CPU'],
                ['id' => 'memory-limits-section', 'label' => 'Memory'],
            ],
            'project.application.resource-operations' => [
                ['id' => 'clone-resource-section', 'label' => 'Clone resource'],
                ['id' => 'move-resource-section', 'label' => 'Move resource'],
            ],
        ];
    @endphp

    <section class="application-settings-workspace mt-8 w-full max-w-[1180px] xl:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
            <aside class="application-settings-navigation min-w-0 xl:sticky xl:top-26 xl:self-start">
                <nav aria-label="Configuration sections"
                    class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0">
                    @foreach ($groupedMenuItems as $groupLabel => $groupItems)
                        @unless ($loop->first)
                            <div class="hidden xl:block my-2 border-t border-neutral-200 dark:border-white/[0.06]" aria-hidden="true"></div>
                        @endunless
                        <div class="nav-section hidden xl:block">{{ $groupLabel }}</div>
                        @foreach ($groupItems as $menuItem)
                            <a wire:key="application-settings-link-{{ str($menuItem['label'])->slug() }}"
                                @class([
                                    'menu-item',
                                    'menu-item-active' => $menuItem['active'],
                                ])
                                {{ wireNavigate() }}
                                href="{{ route($menuItem['route'], $applicationRouteParameters) }}">
                                <x-reicon :name="$menuIcons[$menuItem['label']] ?? 'settings'" class="menu-item-icon" />
                                <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                                @if ($menuItem['badge'] ?? false)
                                    <span class="shrink-0">
                                        <livewire:project.application.server-status-badge :application="$application" />
                                    </span>
                                @endif
                            </a>
                            @if ($menuItem['active'] && count($pageSections[$menuItem['route']] ?? []) >= 4)
                                <div class="nav-children hidden flex-col gap-0.5 py-1 xl:flex" x-data="{ activeSection: '' }">
                                    @foreach ($pageSections[$menuItem['route']] as $section)
                                        <button type="button" class="menu-subitem"
                                            :class="activeSection === '{{ $section['id'] }}' && 'menu-subitem-active'"
                                            @click="activeSection = '{{ $section['id'] }}'; document.getElementById('{{ $section['id'] }}')?.scrollIntoView({ behavior: 'smooth', block: 'start' })">
                                            <span class="menu-item-label text-left">{{ $section['label'] }}</span>
                                        </button>
                                    @endforeach
                                </div>
                            @endif
                        @endforeach
                    @endforeach
                </nav>
            </aside>

            <div class="min-w-0 xl:mt-3">
            @if ($currentRoute === 'project.application.configuration')
                <livewire:project.application.general :application="$application" />
            @elseif ($currentRoute === 'project.application.swarm' && $application->destination->server->isSwarm())
                <livewire:project.application.swarm :application="$application" />
            @elseif ($currentRoute === 'project.application.advanced')
                <livewire:project.application.advanced :application="$application" />
            @elseif ($currentRoute === 'project.application.environment-variables')
                <livewire:project.shared.environment-variable.all :resource="$application" />
            @elseif ($currentRoute === 'project.application.persistent-storage')
                <livewire:project.service.storage :resource="$application" />
            @elseif ($currentRoute === 'project.application.source' && $application->git_based())
                <livewire:project.application.source :application="$application" />
            @elseif ($currentRoute === 'project.application.servers')
                <livewire:project.shared.destination :resource="$application" />
            @elseif ($currentRoute === 'project.application.scheduled-tasks.show')
                <livewire:project.shared.scheduled-task.all :resource="$application" />
            @elseif ($currentRoute === 'project.application.scheduled-tasks')
                <livewire:project.shared.scheduled-task.show />
            @elseif ($currentRoute === 'project.application.webhooks')
                <livewire:project.shared.webhooks :resource="$application" />
            @elseif ($currentRoute === 'project.application.preview-deployments')
                <livewire:project.application.previews :application="$application" />
            @elseif ($currentRoute === 'project.application.healthcheck' && $application->build_pack !== 'dockercompose')
                <livewire:project.shared.health-checks :resource="$application" />
            @elseif ($currentRoute === 'project.application.rollback')
                <livewire:project.application.rollback :application="$application" />
            @elseif ($currentRoute === 'project.application.resource-limits')
                <livewire:project.shared.resource-limits :resource="$application" />
            @elseif ($currentRoute === 'project.application.resource-operations')
                <livewire:project.shared.resource-operations :resource="$application" />
            @elseif ($currentRoute === 'project.application.metrics')
                <livewire:project.shared.metrics :resource="$application" />
            @elseif ($currentRoute === 'project.application.tags')
                <livewire:project.shared.tags :resource="$application" />
            @elseif ($currentRoute === 'project.application.danger')
                <livewire:project.shared.danger :resource="$application" />
            @endif
            </div>
        </div>
    </section>
</div>
