<div>
    <x-slot:title>
        {{ data_get_str($database, 'name')->limit(10) }} > Configuration | Coolify
    </x-slot>

    <livewire:project.database.heading :database="$database" />

    @php
        $databaseRouteParameters = [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'database_uuid' => $database->uuid,
        ];

        $configurationItems = [
            ['label' => 'General', 'route' => 'project.database.configuration', 'icon' => 'settings'],
            ['label' => 'Environment Variables', 'route' => 'project.database.environment-variables', 'icon' => 'variables'],
            ['label' => 'Servers', 'route' => 'project.database.servers', 'icon' => 'servers'],
            ['label' => 'Persistent Storage', 'route' => 'project.database.persistent-storage', 'icon' => 'storages'],
            [
                'label' => 'Import Backup',
                'route' => 'project.database.import-backup',
                'icon' => 'upload',
                'visible' => auth()->user()?->can('update', $database),
            ],
            ['label' => 'Webhooks', 'route' => 'project.database.webhooks', 'icon' => 'notifications'],
            ['label' => 'Healthcheck', 'route' => 'project.database.healthcheck', 'icon' => 'feedback'],
            ['label' => 'Resource Limits', 'route' => 'project.database.resource-limits', 'icon' => 'subscription'],
            ['label' => 'Resource Operations', 'route' => 'project.database.resource-operations', 'icon' => 'teams'],
            ['label' => 'Metrics', 'route' => 'project.database.metrics', 'icon' => 'dashboard'],
            ['label' => 'Tags', 'route' => 'project.database.tags', 'icon' => 'tags'],
            ['label' => 'Danger Zone', 'route' => 'project.database.danger', 'icon' => 'admin'],
        ];

        $configurationItems = collect($configurationItems)
            ->filter(fn (array $item): bool => $item['visible'] ?? true)
            ->map(fn (array $item): array => [
                ...$item,
                'active' => $currentRoute === $item['route'],
            ]);

        $menuGroups = [
            'Settings' => ['General', 'Environment Variables', 'Servers', 'Persistent Storage', 'Import Backup'],
            'Automation' => ['Webhooks', 'Healthcheck'],
            'Operations' => ['Resource Limits', 'Resource Operations', 'Metrics', 'Tags', 'Danger Zone'],
        ];

        $groupedItems = collect($menuGroups)
            ->map(fn (array $labels) => $configurationItems->whereIn('label', $labels)->values())
            ->filter(fn ($items) => $items->isNotEmpty());
    @endphp

    <section class="application-settings-workspace mt-8 w-full max-w-[1180px] xl:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
            <aside class="application-settings-navigation min-w-0 xl:sticky xl:top-26 xl:self-start">
                <nav aria-label="Database settings"
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
                                href="{{ route($menuItem['route'], $databaseRouteParameters) }}">
                                <x-reicon :name="$menuItem['icon']" class="menu-item-icon" />
                                <span class="menu-item-label">{{ $menuItem['label'] }}</span>
                            </a>
                        @endforeach
                    @endforeach
                </nav>
            </aside>

            <div class="min-w-0 xl:mt-3">
                @if ($currentRoute === 'project.database.configuration')
                    @if ($database->type() === 'standalone-postgresql')
                        <livewire:project.database.postgresql.general :database="$database" />
                    @elseif ($database->type() === 'standalone-redis')
                        <livewire:project.database.redis.general :database="$database" />
                    @elseif ($database->type() === 'standalone-mongodb')
                        <livewire:project.database.mongodb.general :database="$database" />
                    @elseif ($database->type() === 'standalone-mysql')
                        <livewire:project.database.mysql.general :database="$database" />
                    @elseif ($database->type() === 'standalone-mariadb')
                        <livewire:project.database.mariadb.general :database="$database" />
                    @elseif ($database->type() === 'standalone-keydb')
                        <livewire:project.database.keydb.general :database="$database" />
                    @elseif ($database->type() === 'standalone-dragonfly')
                        <livewire:project.database.dragonfly.general :database="$database" />
                    @elseif ($database->type() === 'standalone-clickhouse')
                        <livewire:project.database.clickhouse.general :database="$database" />
                    @endif
                @elseif ($currentRoute === 'project.database.environment-variables')
                    <livewire:project.shared.environment-variable.all :resource="$database" />
                @elseif ($currentRoute === 'project.database.servers')
                    <livewire:project.shared.destination :resource="$database" />
                @elseif ($currentRoute === 'project.database.persistent-storage')
                    <livewire:project.service.storage :resource="$database" />
                @elseif ($currentRoute === 'project.database.healthcheck')
                    <livewire:project.database.health :database="$database" />
                @elseif ($currentRoute === 'project.database.import-backup')
                    <livewire:project.database.import :resource="$database" />
                @elseif ($currentRoute === 'project.database.webhooks')
                    <livewire:project.shared.webhooks :resource="$database" />
                @elseif ($currentRoute === 'project.database.resource-limits')
                    <livewire:project.shared.resource-limits :resource="$database" />
                @elseif ($currentRoute === 'project.database.resource-operations')
                    <livewire:project.shared.resource-operations :resource="$database" />
                @elseif ($currentRoute === 'project.database.metrics')
                    <livewire:project.shared.metrics :resource="$database" />
                @elseif ($currentRoute === 'project.database.tags')
                    <livewire:project.shared.tags :resource="$database" />
                @elseif ($currentRoute === 'project.database.danger')
                    <livewire:project.shared.danger :resource="$database" />
                @endif
            </div>
        </div>
    </section>
</div>
