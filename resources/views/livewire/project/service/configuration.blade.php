<div>
    <x-slot:title>
        {{ data_get_str($service, 'name')->limit(10) }} > Configuration | Coolify
    </x-slot>

    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="$query" />

    @php
        $serviceRouteParameters = [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'service_uuid' => $service->uuid,
        ];

        $configurationItems = collect([
            ['label' => 'General', 'route' => 'project.service.configuration', 'icon' => 'settings'],
            ['label' => 'Environment Variables', 'route' => 'project.service.environment-variables', 'icon' => 'variables'],
            ['label' => 'Persistent Storage', 'route' => 'project.service.storages', 'icon' => 'storages'],
            ['label' => 'Scheduled Tasks', 'route' => 'project.service.scheduled-tasks.show', 'icon' => 'terminal'],
            ['label' => 'Webhooks', 'route' => 'project.service.webhooks', 'icon' => 'notifications'],
            ['label' => 'Resource Operations', 'route' => 'project.service.resource-operations', 'icon' => 'teams'],
            ['label' => 'Tags', 'route' => 'project.service.tags', 'icon' => 'tags'],
            ['label' => 'Danger Zone', 'route' => 'project.service.danger', 'icon' => 'admin'],
        ])->map(fn (array $item): array => [
            ...$item,
            'active' => $currentRoute === $item['route']
                || ($item['route'] === 'project.service.scheduled-tasks.show'
                    && str($currentRoute)->startsWith('project.service.scheduled-tasks')),
        ]);

        $menuGroups = [
            'Settings' => ['General', 'Environment Variables', 'Persistent Storage'],
            'Automation' => ['Scheduled Tasks', 'Webhooks'],
            'Operations' => ['Resource Operations', 'Tags', 'Danger Zone'],
        ];

        $groupedItems = collect($menuGroups)
            ->map(fn (array $labels) => $configurationItems->whereIn('label', $labels)->values())
            ->filter(fn ($items) => $items->isNotEmpty());
    @endphp

    <section class="application-settings-workspace mt-8 w-full max-w-[1180px] xl:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
            <aside class="application-settings-navigation min-w-0 xl:sticky xl:top-26 xl:self-start">
                <nav aria-label="Service settings"
                    class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-4 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
                    @foreach ($groupedItems as $groupLabel => $groupItems)
                        @unless ($loop->first)
                            <div class="my-2 hidden border-t border-neutral-200 xl:block dark:border-white/[0.06]"
                                aria-hidden="true"></div>
                        @endunless
                        <div class="nav-section hidden xl:block">{{ $groupLabel }}</div>
                        @foreach ($groupItems as $menuItem)
                            <a @class([
                                'menu-item',
                                'menu-item-active' => $menuItem['active'],
                            ])
                                {{ wireNavigate() }}
                                href="{{ route($menuItem['route'], $serviceRouteParameters) }}">
                                <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                                <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                            </a>
                        @endforeach
                    @endforeach
                </nav>
            </aside>

            <div class="min-w-0 xl:mt-3">
                @if ($currentRoute === 'project.service.configuration')
                    <livewire:project.service.stack-form :service="$service" />

                    <div class="mt-8">
                        <div class="mb-3 flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-base font-semibold text-black dark:text-fg">Compose resources</h2>
                                <p class="mt-1 text-sm text-neutral-500 dark:text-fg-dim">
                                    Applications and databases defined in this service.
                                </p>
                            </div>
                            <a class="button" target="_blank" href="{{ $service->documentation() }}">
                                Documentation
                                <x-reicon name="external-link" class="size-4" />
                            </a>
                        </div>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            @if ($applications->isEmpty() && $databases->isEmpty())
                                <div
                                    class="application-settings-section overflow-hidden sm:col-span-2">
                                    <x-empty title="No compose resources"
                                        description="No applications or databases are defined in this Docker Compose file.">
                                        <x-slot:icon>
                                            <x-reicon name="grid" class="size-5" />
                                        </x-slot:icon>
                                    </x-empty>
                                </div>
                            @endif

                            @foreach ($applications as $application)
                                <livewire:project.service.resource-card :service="$service" :resource="$application"
                                    :parameters="$parameters"
                                    wire:key="service-application-card-{{ $application->id }}" />
                            @endforeach
                            @foreach ($databases as $database)
                                <livewire:project.service.resource-card :service="$service" :resource="$database"
                                    :parameters="$parameters"
                                    wire:key="service-database-card-{{ $database->id }}" />
                            @endforeach
                        </div>
                    </div>
                @elseif ($currentRoute === 'project.service.environment-variables')
                    <livewire:project.shared.environment-variable.all :resource="$service" />
                @elseif ($currentRoute === 'project.service.storages')
                    <div class="space-y-6">
                        @foreach ($applications as $application)
                            <livewire:project.service.storage wire:key="application-{{ $application->id }}"
                                :resource="$application" />
                        @endforeach
                        @foreach ($databases as $database)
                            <livewire:project.service.storage wire:key="database-{{ $database->id }}"
                                :resource="$database" />
                        @endforeach
                    </div>
                @elseif ($currentRoute === 'project.service.scheduled-tasks.show')
                    <livewire:project.shared.scheduled-task.all :resource="$service" />
                @elseif ($currentRoute === 'project.service.scheduled-tasks')
                    <livewire:project.shared.scheduled-task.show />
                @elseif ($currentRoute === 'project.service.webhooks')
                    <livewire:project.shared.webhooks :resource="$service" />
                @elseif ($currentRoute === 'project.service.resource-operations')
                    <livewire:project.shared.resource-operations :resource="$service" />
                @elseif ($currentRoute === 'project.service.tags')
                    <livewire:project.shared.tags :resource="$service" />
                @elseif ($currentRoute === 'project.service.danger')
                    <livewire:project.shared.danger :resource="$service" />
                @endif
            </div>
        </div>
    </section>
</div>
