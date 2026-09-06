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
        $selectedResourceUuid = data_get($parameters, 'stack_service_uuid');
        $selectedResource = $selectedResourceUuid
            ? $service->applications->firstWhere('uuid', $selectedResourceUuid)
                ?? $service->databases->firstWhere('uuid', $selectedResourceUuid)
            : null;
        $displayStatus = $selectedResource?->status ?? $service->status;
        $selectedResourceStatus = str($selectedResource?->status ?? '');
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
                    <x-status-summary :status="$displayStatus" :title="$selectedResource ? 'Resource status' : 'Service status'"
                        :container-name="$selectedResource ? 'Container' : 'Containers'" />
                    <x-services.links :service="$service" compact />
                </div>
                <div class="flex w-full flex-wrap gap-1">
                    @if ($selectedResource)
                        <x-application.restart-limit-warning :application="$selectedResource" />
                    @endif
                </div>
            </div>
        </div>

        <div class="w-full xl:hidden">
            @if ($service->isDeployable)
                @can('deploy', $service)
                <div id="service-mobile-actions" class="relative mb-3"
                    x-data="{ open: false }" @click.outside="open = false"
                    @keydown.escape.window="open = false">
                    <button type="button" class="button w-full justify-between" x-bind:disabled="deploying"
                        @click="open = !open"
                        :aria-expanded="open" aria-haspopup="menu">
                        <span class="inline-flex items-center gap-2">
                            <x-loading-on-button x-show="deploying" x-cloak />
                            <span x-text="deploying ? 'Deploying…' : 'Actions'">Actions</span>
                        </span>
                        <span class="inline-flex transition-transform" :class="open && 'rotate-180'">
                            <x-reicon name="chevron-down" class="size-3 opacity-55" />
                        </span>
                    </button>

                    <div x-cloak x-show="open" x-transition.origin.top.left
                        class="listbox-panel top-full! left-0! right-0! mt-1! w-full! min-w-0!" role="menu">
                        @if ($selectedResource && $selectedResource->container_present !== false && $selectedResourceStatus->startsWith('exited'))
                            <button type="button" class="listbox-option justify-start! gap-2.5!"
                                @click="open = false; document.getElementById('selected-resource-remove-trigger')?.click()"
                                role="menuitem">
                                <x-reicon name="trash" class="size-3.5 text-error" />
                                Remove container
                            </button>
                        @elseif ($serviceStatus->contains('running') || $serviceStatus->contains('degraded'))
                            @can('deploy', $service)
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    @click="open = false; document.getElementById('service-restart-trigger')?.click()"
                                    role="menuitem">
                                    <x-reicon name="restart" class="size-3.5 opacity-70" />
                                    Restart current version
                                </button>
                            @else
                                <button type="button" class="listbox-option justify-start! gap-2.5!" disabled
                                    role="menuitem">
                                    <x-reicon name="restart" class="size-3.5 opacity-70" />
                                    Restart current version
                                </button>
                            @endcan
                            @if ($serviceStatus->contains('running'))
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    @disabled(!auth()->user()->can('deploy', $service))
                                    @click="$wire.dispatch('pullAndRestartEvent'); open = false"
                                    role="menuitem">
                                    <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                    Pull latest and restart
                                </button>
                            @else
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    @disabled(!auth()->user()->can('deploy', $service))
                                    @click="$wire.dispatch('forceDeployEvent'); open = false"
                                    role="menuitem">
                                    <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                    Force Restart
                                </button>
                            @endif
                            @can('stop', $service)
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    @click="open = false; document.getElementById('service-stop-trigger')?.click()"
                                    role="menuitem">
                                    <x-reicon name="stop-circle" class="size-3.5 text-error" />
                                    Stop
                                </button>
                            @else
                                <button type="button" class="listbox-option justify-start! gap-2.5!" disabled
                                    role="menuitem">
                                    <x-reicon name="stop-circle" class="size-3.5 opacity-70" />
                                    Stop
                                </button>
                            @endcan
                        @else
                            @can('deploy', $service)
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    @click="open = false; deploying = true; $wire.dispatch('startEvent')" role="menuitem">
                                    <x-reicon name="play-circle" class="size-3.5 opacity-70" />
                                    Deploy
                                </button>
                            @else
                                <button type="button" class="listbox-option justify-start! gap-2.5!" disabled
                                    role="menuitem">
                                    <x-reicon name="play-circle" class="size-3.5 opacity-70" />
                                    Deploy
                                </button>
                            @endcan
                            <button type="button" class="listbox-option justify-start! gap-2.5!"
                                @disabled(!auth()->user()->can('deploy', $service))
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
                    </div>
                </div>
                @endcan
            @else
                @can('deploy', $service)
                    <div id="service-mobile-actions" class="relative mb-3"
                        x-data="{ open: false }" @click.outside="open = false"
                        @keydown.escape.window="open = false">
                        <button type="button" class="button w-full justify-between" @click="open = !open"
                            :aria-expanded="open" aria-haspopup="menu">
                            <span>Actions</span>
                            <span class="inline-flex transition-transform" :class="open && 'rotate-180'">
                                <x-reicon name="chevron-down" class="size-3 opacity-55" />
                            </span>
                        </button>

                        <div x-cloak x-show="open" x-transition.origin.top.left
                            class="listbox-panel top-full! left-0! right-0! mt-1! w-full! min-w-0!" role="menu">
                            <div class="listbox-option cursor-default! justify-start! gap-2.5! text-neutral-400! dark:text-fg-faint!"
                                role="menuitem" aria-disabled="true">
                                <x-reicon name="play-circle" class="size-3.5 opacity-70" />
                                <span>Deploy (<a href="{{ $environmentVariablesUrl }}" {{ wireNavigate() }}
                                        class="cursor-pointer underline underline-offset-2">missing required env vars</a>)</span>
                            </div>
                        </div>
                    </div>
                @endcan
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
                        <div id="service-desktop-actions" class="relative" x-data="{ open: false }"
                                x-effect="$dispatch('resource-actions-toggled', { open })"
                                @click.outside="open = false" @keydown.escape.window="open = false">
                                <button type="button" class="button button-highlighted" @click="open = !open" :aria-expanded="open">
                                    Actions
                                    <x-reicon name="chevron-down" class="size-3 opacity-55" />
                                </button>
                                <div x-cloak x-show="open" x-transition.origin.top.right
                                    class="listbox-panel top-full! right-0! left-auto! mt-1! w-64! min-w-0!" role="menu">
                                    @if ($selectedResource && $selectedResource->container_present !== false && $selectedResourceStatus->startsWith('exited'))
                                        <button type="button" class="listbox-option justify-start! gap-2.5!"
                                            @click="open = false; document.getElementById('selected-resource-remove-trigger')?.click()"
                                            role="menuitem">
                                            <x-reicon name="trash" class="size-3.5 text-error" />
                                            Remove container
                                        </button>
                                    @else
                                    @if ($serviceStatus->contains('running') || $serviceStatus->contains('degraded'))
                                        <button type="button" class="listbox-option justify-start! gap-2.5!"
                                            @disabled(!auth()->user()->can('deploy', $service))
                                            @click="open = false; document.getElementById('service-restart-trigger')?.click()">
                                            <x-reicon name="restart" class="size-3.5 opacity-70" />
                                            Restart
                                        </button>
                                        @if ($serviceStatus->contains('running'))
                                            <button type="button" class="listbox-option justify-start! gap-2.5!"
                                                @disabled(!auth()->user()->can('deploy', $service))
                                                @click="$wire.dispatch('pullAndRestartEvent'); open = false">
                                                <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                                Restart (pull latest)
                                            </button>
                                        @endif
                                        <button type="button" class="listbox-option justify-start! gap-2.5!"
                                            @disabled(!auth()->user()->can('stop', $service))
                                            @click="open = false; document.getElementById('service-stop-trigger')?.click()">
                                            <x-reicon name="stop-circle" class="size-3.5 text-error" />
                                            Stop
                                        </button>
                                    @elseif (! $serviceStatus->contains('running'))
                                        <button type="button" class="listbox-option justify-start! gap-2.5!"
                                            @disabled(!auth()->user()->can('deploy', $service))
                                            @click="deploying = true; $wire.dispatch('startEvent'); open = false">
                                            <x-reicon name="play-circle" class="size-3.5 opacity-70" />
                                            Deploy
                                        </button>
                                    @endif
                                    @if (! $serviceStatus->contains('running'))
                                        <div class="my-1 border-t border-coolgray-200 dark:border-coolgray-300" role="separator"></div>
                                    @endif
                                    @if ($serviceStatus->contains('degraded'))
                                        <button type="button" class="listbox-option justify-start! gap-2.5!"
                                            @disabled(!auth()->user()->can('deploy', $service))
                                            @click="$wire.dispatch('forceDeployEvent'); open = false">
                                            <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                            Force Restart
                                        </button>
                                    @elseif (! $serviceStatus->contains('running'))
                                        <button type="button" class="listbox-option justify-start! gap-2.5!"
                                            @disabled(!auth()->user()->can('deploy', $service))
                                            @click="$wire.dispatch('forceDeployEvent'); open = false">
                                            <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                            Force Deploy
                                        </button>
                                        <button type="button" class="listbox-option justify-start! gap-2.5!"
                                            @disabled(!auth()->user()->can('stop', $service))
                                            @click="$wire.dispatch('cleanupEvent'); open = false">
                                            <x-reicon name="trash" class="size-3.5 opacity-70" />
                                            Force Cleanup Containers
                                        </button>
                                    @endif
                                    @endif
                                </div>
                        </div>
                        @endcan
                    @else
                        @can('deploy', $service)
                            <div id="service-desktop-actions" class="relative" x-data="{ open: false }"
                                x-effect="$dispatch('resource-actions-toggled', { open })"
                                @click.outside="open = false" @keydown.escape.window="open = false">
                                <button type="button" class="button button-highlighted" @click="open = !open"
                                    :aria-expanded="open" aria-haspopup="menu">
                                    Actions
                                    <x-reicon name="chevron-down" class="size-3 opacity-55" />
                                </button>
                                <div x-cloak x-show="open" x-transition.origin.top.right
                                    class="listbox-panel top-full! right-0! left-auto! mt-1! w-64! min-w-0!" role="menu">
                                    <div class="listbox-option cursor-default! justify-start! gap-2.5! text-neutral-400! dark:text-fg-faint!"
                                        role="menuitem" aria-disabled="true">
                                        <x-reicon name="play-circle" class="size-3.5 opacity-70" />
                                        <span>Deploy (<a href="{{ $environmentVariablesUrl }}" {{ wireNavigate() }}
                                                class="cursor-pointer underline underline-offset-2">missing required env vars</a>)</span>
                                    </div>
                                </div>
                            </div>
                        @endcan
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
