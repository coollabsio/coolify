<div>
    <x-slot:title>
        {{ data_get_str($service, 'name')->limit(10) }} > Configuration | Coolify
    </x-slot>

    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="$query" />

    @php
        $storageSections = $applications
            ->concat($databases)
            ->map(fn ($resource): array => [
                'id' => 'storage-service-'.$resource->uuid,
                'label' => Str::headline($resource->name),
            ]);
    @endphp

    <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
            <x-service.configuration-sidebar :service="$service" :current-route="$currentRoute"
                :storage-sections="$storageSections" />

            <div class="min-w-0">
                @if ($currentRoute === 'project.service.configuration')
                    <livewire:project.service.stack-form :service="$service" />

                    <div class="mt-8" x-data="{
                        viewMode: localStorage.getItem('service-compose-resources-view') || 'table',
                        setViewMode(mode) {
                            this.viewMode = mode;
                            localStorage.setItem('service-compose-resources-view', mode);
                        }
                    }">
                        <x-application.settings-section id="compose-resources-section" title="Compose resources"
                            description="Applications and databases defined in this service." flush>
                            <x-slot:actions>
                                @if ($applications->isNotEmpty() || $databases->isNotEmpty())
                                    <x-canvas-view-toggle />
                                @endif
                                <a class="button" target="_blank" href="{{ $service->documentation() }}">
                                    Documentation
                                    <x-reicon name="external-link" class="size-4" />
                                </a>
                            </x-slot:actions>

                            <div :class="viewMode === 'grid'
                                ? 'grid grid-cols-1 gap-3 p-3 sm:grid-cols-2'
                                : 'overflow-x-auto rounded-lg'">
                                @if ($applications->isNotEmpty() || $databases->isNotEmpty())
                                    <div x-cloak x-show="viewMode === 'table'"
                                        class="grid min-w-[48rem] grid-cols-[minmax(14rem,1fr)_minmax(12rem,1fr)_12rem_5rem] gap-3 border-b border-neutral-200 bg-neutral-50 px-4 py-2.5 text-[11px] font-medium text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.05] dark:text-fg-faint">
                                        <div>Resource</div>
                                        <div>Image</div>
                                        <div class="justify-self-start">Status</div>
                                        <div></div>
                                    </div>
                                @endif

                                @if ($applications->isEmpty() && $databases->isEmpty())
                                    <div class="p-4 sm:col-span-2">
                                        <x-empty title="No compose resources"
                                            description="No applications or databases are defined in this Docker Compose file."
                                            icon-name="grid" />
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
                        </x-application.settings-section>
                    </div>
                @elseif ($currentRoute === 'project.service.analytics')
                    <livewire:project.service.analytics :service="$service"
                        :lazy="(bool) ($service->server ?? $service->destination?->server)?->isTrafficAnalyticsEnabled()" />
                @elseif ($currentRoute === 'project.service.domains')
                    <livewire:project.service.domains :service="$service" />
                    <livewire:project.shared.maintenance :resource="$service" />
                @elseif ($currentRoute === 'project.service.environment-variables')
                    <livewire:project.shared.environment-variable.all :resource="$service" />
                    <livewire:project.shared.secret-manager-links :resource="$service" />
                @elseif ($currentRoute === 'project.service.storages')
                    <div class="space-y-6">
                        <div
                            class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] leading-5 text-amber-800 dark:border-warning/15 dark:bg-warning/[0.07] dark:text-amber-300/90">
                            Service volume mounts are read-only here. Edit the Docker Compose file and reload it to change volumes.
                        </div>
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
