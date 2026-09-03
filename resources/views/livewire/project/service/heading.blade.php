<nav wire:poll.10000ms="checkStatus" class="w-full max-w-none pb-4 md:pb-6 lg:pb-0">
    @php
        $servicePageItems = [
            [
                'label' => 'Settings',
                'route' => 'project.service.configuration',
                'active' => request()->routeIs('project.service.configuration')
                    || request()->routeIs('project.service.domains')
                    || request()->routeIs('project.service.environment-variables')
                    || request()->routeIs('project.service.storages')
                    || request()->routeIs('project.service.scheduled-tasks*')
                    || request()->routeIs('project.service.webhooks')
                    || request()->routeIs('project.service.resource-operations')
                    || request()->routeIs('project.service.tags')
                    || request()->routeIs('project.service.danger')
                    || request()->routeIs('project.service.index*')
                    || request()->routeIs('project.service.database.*'),
            ],
            [
                'label' => 'Backups',
                'route' => 'project.service.volume-backups.index',
                'active' => request()->routeIs('project.service.volume-backups.*'),
            ],
            [
                'label' => 'Runtime Logs',
                'route' => 'project.service.logs',
                'active' => request()->routeIs('project.service.logs'),
                'navigate' => false,
            ],
            [
                'label' => 'Terminal',
                'route' => 'project.service.command',
                'active' => request()->routeIs('project.service.command'),
                'navigate' => false,
                'visible' => auth()->user()?->can('canAccessTerminal'),
            ],
        ];

        $servicePageItems = array_values(array_filter(
            $servicePageItems,
            fn (array $item): bool => $item['visible'] ?? true,
        ));

        $serviceStatus = str($service->status ?? 'exited');
        $environmentVariablesUrl = route('project.service.environment-variables', [
            'project_uuid' => $service->environment->project->uuid,
            'environment_uuid' => $service->environment->uuid,
            'service_uuid' => $service->uuid,
        ]);
    @endphp

    <livewire:project.shared.configuration-checker :resource="$service" />

    <x-process-dialog @startservice.window="processDialogOpen = true" closeWithX>
        <x-slot:title>Service Startup</x-slot:title>
        <x-slot:content>
            <livewire:activity-monitor header="Logs" fullHeight />
        </x-slot:content>
    </x-process-dialog>

    <div x-data="{ deploying: false }" @service-deploy-finished.window="deploying = false">
        <div class="mb-3 w-full xl:hidden">
            <div class="flex min-w-0 flex-col items-start gap-2">
                <h1 class="min-w-0 max-w-full truncate text-[24px]! leading-7! font-semibold! tracking-tight! text-black dark:text-fg">
                    {{ $service->name }}
                </h1>
                <div class="relative flex w-full min-w-0 items-center gap-2">
                    <x-status-summary :status="$service->status" title="Service status" container-name="Containers" />
                    <x-services.links :service="$service" compact />
                </div>
            </div>
        </div>

        <div class="w-full xl:hidden">
            @if ($service->isDeployable)
                @can('deploy', $service)
                    <x-split-action id="service-mobile-actions" class="mb-3 flex w-full">
                        @if ($selectedResource && $selectedResource->container_present !== false && $selectedResourceStatus->startsWith('exited'))
                            <x-slot:main @click="document.getElementById('selected-resource-remove-trigger')?.click()">
                                <x-reicon name="trash" class="size-3.5" />
                                Remove container
                            </x-slot:main>
                        @elseif ($serviceStatus->contains('running') || $serviceStatus->contains('degraded'))
                            <x-slot:main x-bind:disabled="deploying"
                                @click="document.getElementById('service-restart-trigger')?.click()">
                                <x-reicon name="restart" class="size-3.5" />
                                Restart
                            </x-slot:main>
                            @if ($serviceStatus->contains('running'))
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    @click="$wire.dispatch('pullAndRestartEvent'); open = false" role="menuitem">
                                    <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                    Restart (pull latest)
                                </button>
                            @endif
                            @if ($serviceStatus->contains('degraded'))
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    @click="$wire.dispatch('forceDeployEvent'); open = false" role="menuitem">
                                    <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                    Force Restart
                                </button>
                            @endif
                            <button type="button" class="listbox-option justify-start! gap-2.5!"
                                @disabled(!auth()->user()->can('stop', $service))
                                @click="open = false; document.getElementById('service-stop-trigger')?.click()" role="menuitem">
                                <x-reicon name="stop-circle" class="size-3.5 text-error" />
                                Stop
                            </button>
                        @else
                            <x-slot:main x-bind:disabled="deploying" @click="deploying = true; $wire.dispatch('startEvent')">
                                <x-loading-on-button x-show="deploying" x-cloak />
                                <x-reicon name="play-circle" class="size-3.5" x-show="!deploying" />
                                <span x-text="deploying ? 'Deploying…' : 'Deploy'">Deploy</span>
                            </x-slot:main>
                            <button type="button" class="listbox-option justify-start! gap-2.5!"
                                @click="$wire.dispatch('forceDeployEvent'); open = false" role="menuitem">
                                <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                Force Deploy
                            </button>
                            <button type="button" class="listbox-option justify-start! gap-2.5!"
                                @disabled(!auth()->user()->can('stop', $service))
                                @click="$wire.dispatch('cleanupEvent'); open = false" role="menuitem">
                                <x-reicon name="trash" class="size-3.5 opacity-70" />
                                Force Cleanup Containers
                            </button>
                        @endif
                    </x-split-action>
                @endcan
            @else
                <a href="{{ $environmentVariablesUrl }}" {{ wireNavigate() }}
                    class="mb-3 inline-flex" aria-label="Open required environment variables">
                    <x-status-badge status="Required variables missing" type="error" />
                </a>
            @endif

        </div>

        @teleport('#resource-action-hud-slot')
        <div class="hidden w-full items-center xl:flex xl:w-auto">
            <div
                class="resource-heading-navbar application-heading-actions flex w-auto min-w-0 items-center justify-end gap-1 overflow-visible">
                <div class="resource-heading-actions flex shrink-0 items-center gap-0.5">
                    @if ($service->isDeployable)
                        <div class="resource-heading-menus shrink-0">
                            <x-services.links :service="$service" />
                        </div>
                        @can('deploy', $service)
                            <x-split-action id="service-desktop-actions">
                                @if ($selectedResource && $selectedResource->container_present !== false && $selectedResourceStatus->startsWith('exited'))
                            <x-slot:main @click="document.getElementById('selected-resource-remove-trigger')?.click()">
                                <x-reicon name="trash" class="size-3.5" />
                                Remove container
                            </x-slot:main>
                        @elseif ($serviceStatus->contains('running') || $serviceStatus->contains('degraded'))
                                    <x-slot:main x-bind:disabled="deploying"
                                        @click="document.getElementById('service-restart-trigger')?.click()">
                                        <x-reicon name="restart" class="size-3.5" />
                                        Restart
                                    </x-slot:main>
                                    @if ($serviceStatus->contains('running'))
                                        <button type="button" class="listbox-option justify-start! gap-2.5!"
                                            @click="$wire.dispatch('pullAndRestartEvent'); open = false" role="menuitem">
                                            <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                            Restart (pull latest)
                                        </button>
                                    @endif
                                    @if ($serviceStatus->contains('degraded'))
                                        <button type="button" class="listbox-option justify-start! gap-2.5!"
                                            @click="$wire.dispatch('forceDeployEvent'); open = false" role="menuitem">
                                            <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                            Force Restart
                                        </button>
                                    @endif
                                    <button type="button" class="listbox-option justify-start! gap-2.5!"
                                        @disabled(!auth()->user()->can('stop', $service))
                                        @click="open = false; document.getElementById('service-stop-trigger')?.click()" role="menuitem">
                                        <x-reicon name="stop-circle" class="size-3.5 text-error" />
                                        Stop
                                    </button>
                                @else
                                    <x-slot:main x-bind:disabled="deploying" @click="deploying = true; $wire.dispatch('startEvent')">
                                        <x-loading-on-button x-show="deploying" x-cloak />
                                        <x-reicon name="play-circle" class="size-3.5" x-show="!deploying" />
                                        <span x-text="deploying ? 'Deploying…' : 'Deploy'">Deploy</span>
                                    </x-slot:main>
                                    <button type="button" class="listbox-option justify-start! gap-2.5!"
                                        @click="$wire.dispatch('forceDeployEvent'); open = false" role="menuitem">
                                        <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                        Force Deploy
                                    </button>
                                    <button type="button" class="listbox-option justify-start! gap-2.5!"
                                        @disabled(!auth()->user()->can('stop', $service))
                                        @click="$wire.dispatch('cleanupEvent'); open = false" role="menuitem">
                                        <x-reicon name="trash" class="size-3.5 opacity-70" />
                                        Force Cleanup Containers
                                    </button>
                                @endif
                            </x-split-action>
                        @endcan
                    @else
                        <a href="{{ $environmentVariablesUrl }}" {{ wireNavigate() }}
                            aria-label="Open required environment variables">
                            <x-status-badge status="Required variables missing" type="error" />
                        </a>
                    @endif
                </div>
            </div>
        </div>
        @endteleport

    </div>

    @if ($service->isDeployable)
        <div class="hidden" aria-hidden="true">
            <x-modal-confirmation title="Confirm Service Restart?" buttonTitle="Restart"
                submitAction="restartEvent" :dispatchAction="true" :actions="['This service will be restarted.']"
                :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Confirm">
                <x-slot:trigger>
                    <button id="service-restart-trigger" type="button">Restart</button>
                </x-slot:trigger>
            </x-modal-confirmation>
            <x-modal-confirmation title="Confirm Service Stopping?" buttonTitle="Stop"
                submitAction="stop" :checkboxes="$checkboxes" :actions="[__('service.stop'), __('resource.non_persistent')]"
                :confirmWithText="false" :confirmWithPassword="false" step1ButtonText="Continue"
                step2ButtonText="Confirm">
                <x-slot:trigger>
                    <button id="service-stop-trigger" type="button">Stop</button>
                </x-slot:trigger>
            </x-modal-confirmation>
            @if ($selectedResource)
                <x-modal-confirmation title="Confirm Container Removal?" buttonTitle="Remove container"
                    canGate="deploy" :canResource="$service" submitAction="removeSelectedResourceContainer"
                    :actions="['The exited service resource container will be removed.', __('resource.non_persistent')]"
                    :confirmWithText="false" :confirmWithPassword="false" step1ButtonText="Continue"
                    step2ButtonText="Confirm">
                    <x-slot:trigger>
                        <button id="selected-resource-remove-trigger" type="button">Remove container</button>
                    </x-slot:trigger>
                </x-modal-confirmation>
            @endif
        </div>
    @endif

    @script
        <script>
            $wire.$on('stopEvent', () => {
                $wire.$dispatch('info',
                    'Gracefully stopping service.<br/><br/>It could take a while depending on the service.');
                $wire.$call('stop');
            });
            $wire.$on('startEvent', async () => {
                try {
                    const isDeploymentProgress = await $wire.$call('checkDeployments');

                    if (isDeploymentProgress) {
                        $wire.$dispatch('error',
                            'There is a deployment in progress.<br><br>You can force deploy from the Actions menu.');
                        return;
                    }

                    await $wire.$call('start');
                } finally {
                    window.dispatchEvent(new CustomEvent('service-deploy-finished'));
                }
            });
            $wire.$on('restartEvent', async () => {
                const isDeploymentProgress = await $wire.$call('checkDeployments');

                if (isDeploymentProgress) {
                    $wire.$dispatch('error',
                        'There is a deployment in progress.<br><br>You can force deploy from the Actions menu.');
                    return;
                }

                $wire.$dispatch('info',
                    'Gracefully stopping service.<br/><br/>It could take a while depending on the service.');
                $wire.$call('restart');
            });
            $wire.$on('forceDeployEvent', () => $wire.$call('forceDeploy'));
            $wire.$on('pullAndRestartEvent', () => {
                $wire.$dispatch('info', 'Pulling new images and restarting service.');
                $wire.$call('pullAndRestartEvent');
            });
            $wire.$on('cleanupEvent', () => $wire.$call('stop', true));
            $wire.on('imagePulled', () => {
                window.dispatchEvent(new CustomEvent('startservice'));
                $wire.$dispatch('info', 'Restarting service.');
            });
        </script>
    @endscript
</nav>
