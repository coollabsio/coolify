<nav wire:poll.10000ms="checkStatus" class="w-full max-w-[1180px] pb-4 md:pb-6 lg:pb-0">
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
                'label' => 'Runtime Logs',
                'route' => 'project.service.logs',
                'active' => request()->routeIs('project.service.logs'),
                'navigate' => false,
            ],
            [
                'label' => 'Console',
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
        [$serviceStatusLabel, $serviceStatusType] = match (true) {
            $serviceStatus->contains('running') => ['Running', 'success'],
            $serviceStatus->contains('degraded') => ['Degraded', 'warning'],
            $serviceStatus->contains('restarting'),
            $serviceStatus->contains('starting') => ['Starting', 'warning'],
            $serviceStatus->contains('exited') => ['Stopped', 'neutral'],
            default => ['Deploying', 'warning'],
        };
    @endphp

    <livewire:project.shared.configuration-checker :resource="$service" />

    <x-process-dialog @startservice.window="processDialogOpen = true" closeWithX>
        <x-slot:title>Service Startup</x-slot:title>
        <x-slot:content>
            <livewire:activity-monitor header="Logs" fullHeight />
        </x-slot:content>
    </x-process-dialog>

    @teleport('#server-topbar-context')
        <div class="flex min-w-0 items-center gap-1 text-[13px]">
            <span class="shrink-0 px-0.5 text-neutral-300 dark:text-fg-faint">/</span>
            <span class="flex min-w-0 shrink items-center gap-2 px-1">
                <span class="max-w-48 min-w-0 truncate font-semibold text-black dark:text-fg xl:max-w-64">
                    {{ $service->name }}
                </span>
                <x-status-badge :status="$serviceStatusLabel" :type="$serviceStatusType" />
            </span>
        </div>
    @endteleport

    <div x-data>
        <div class="mb-3 w-full lg:hidden">
            <div class="flex min-w-0 flex-wrap items-center gap-2">
                <h1 class="min-w-0 truncate text-[24px]! leading-7! font-semibold! tracking-tight! text-black dark:text-fg">
                    {{ $service->name }}
                </h1>
                <x-status-badge :status="$serviceStatusLabel" :type="$serviceStatusType" />
            </div>
        </div>

        <div class="w-full md:hidden">
            @if ($service->isDeployable)
                <div id="service-mobile-actions" class="relative mb-3"
                    x-data="{ open: false }" @click.outside="open = false"
                    @keydown.escape.window="open = false">
                    <button type="button" class="button w-full justify-between" @click="open = !open"
                        :aria-expanded="open" aria-haspopup="menu">
                        <span class="inline-flex items-center gap-2">
                            <x-reicon name="play-circle" class="size-3.5 opacity-70" />
                            Actions
                        </span>
                        <span class="inline-flex transition-transform" :class="open && 'rotate-180'">
                            <x-reicon name="chevron-down" class="size-3 opacity-55" />
                        </span>
                    </button>

                    <div x-cloak x-show="open" x-transition.origin.top.left
                        class="listbox-panel top-full! left-0! right-0! mt-1! w-full! min-w-0!" role="menu">
                        @if ($serviceStatus->contains('running') || $serviceStatus->contains('degraded'))
                            @can('deploy', $service)
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    @click="open = false; document.getElementById('service-restart-trigger')?.click()"
                                    role="menuitem">
                                    <x-reicon name="restart" class="size-3.5 text-orange-500 dark:text-warning" />
                                    Restart
                                </button>
                            @else
                                <button type="button" class="listbox-option justify-start! gap-2.5!" disabled
                                    role="menuitem">
                                    <x-reicon name="restart" class="size-3.5 opacity-70" />
                                    Restart
                                </button>
                            @endcan
                            @can('stop', $service)
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    @click="open = false; document.getElementById('service-stop-trigger')?.click()"
                                    role="menuitem">
                                    <x-reicon name="stop" class="size-3.5 text-error" />
                                    Stop
                                </button>
                            @else
                                <button type="button" class="listbox-option justify-start! gap-2.5!" disabled
                                    role="menuitem">
                                    <x-reicon name="stop" class="size-3.5 opacity-70" />
                                    Stop
                                </button>
                            @endcan
                            @if ($serviceStatus->contains('running'))
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    @disabled(!auth()->user()->can('deploy', $service))
                                    @click="$wire.dispatch('pullAndRestartEvent'); open = false"
                                    role="menuitem">
                                    <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                    Pull Latest Images & Restart
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
                        @else
                            @can('deploy', $service)
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    @click="open = false; $wire.dispatch('startEvent')" role="menuitem">
                                    <x-reicon name="play-circle" class="size-3.5 text-warning" />
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
            @endif

            <div
                class="flex min-w-0 items-center gap-0.5 rounded-[10px] border border-neutral-200 bg-neutral-100 p-1 dark:border-white/[0.07] dark:bg-white/[0.035]">
                {{-- Tabs may scroll; keep Links outside overflow so the dropdown never creates a scrollbar. --}}
                <x-resource-heading-tabs class="min-w-0 flex-1">
                    @foreach ($servicePageItems as $menuItem)
                        <a @class([
                            'app-tab shrink-0',
                            'bg-coollabs/10 text-coollabs ring-1 ring-coollabs/25 dark:bg-warning/15 dark:text-warning dark:ring-warning/25' => $menuItem['active'],
                        ])
                            @if ($menuItem['navigate'] ?? true) {{ wireNavigate() }} @endif
                            href="{{ route($menuItem['route'], $parameters) }}">
                            {{ $menuItem['label'] }}
                        </a>
                    @endforeach
                </x-resource-heading-tabs>
                <div class="resource-heading-menus shrink-0">
                    <x-services.links :service="$service" />
                </div>
            </div>
        </div>

        <div class="hidden w-full items-center md:flex lg:fixed lg:top-12 lg:right-0 lg:z-30 lg:h-12 lg:w-auto lg:border-b lg:border-neutral-200 lg:bg-white/95 lg:pr-4 lg:pl-2 lg:backdrop-blur lg:transition-[left] lg:duration-200 lg:dark:border-white/[0.06] lg:dark:bg-panel/95"
            :class="[typeof collapsed !== 'undefined' && collapsed ? 'lg:left-16' : 'lg:left-56']">
            <div
                class="resource-heading-navbar application-heading-actions flex w-full min-w-0 items-center justify-between gap-2 overflow-visible rounded-[10px] border border-neutral-200 bg-neutral-100 p-1 dark:border-white/[0.07] dark:bg-white/[0.035]">
                <div class="flex min-w-0 items-center gap-0.5">
                    <x-resource-heading-tabs class="min-w-0">
                        @foreach ($servicePageItems as $menuItem)
                            <a wire:key="service-primary-nav-{{ str($menuItem['label'])->slug() }}"
                                @class([
                                    'app-tab shrink-0',
                                    'bg-coollabs/10 text-coollabs shadow-sm ring-1 ring-coollabs/25 hover:bg-coollabs/15 dark:bg-warning/15 dark:text-warning dark:ring-warning/25 dark:hover:bg-warning/20' => $menuItem['active'],
                                ])
                                @if ($menuItem['navigate'] ?? true) {{ wireNavigate() }} @endif
                                href="{{ route($menuItem['route'], $parameters) }}">
                                {{ $menuItem['label'] }}
                            </a>
                        @endforeach
                    </x-resource-heading-tabs>
                    <div class="resource-heading-menus shrink-0">
                        <x-services.links :service="$service" />
                    </div>
                </div>

                <div class="resource-heading-actions flex shrink-0 items-center gap-0.5 border-l border-neutral-200 pl-1 dark:border-white/[0.08]">
                    @if ($service->isDeployable)
                        <x-services.advanced :service="$service" />
                        @if ($serviceStatus->contains('running') || $serviceStatus->contains('degraded'))
                            <x-forms.button canGate="deploy" :canResource="$service"
                                @click="document.getElementById('service-restart-trigger')?.click()">
                                <x-reicon name="restart" class="size-4 text-orange-500 dark:text-warning" />
                                Restart
                            </x-forms.button>
                            <x-forms.button canGate="stop" :canResource="$service" isError
                                @click="document.getElementById('service-stop-trigger')?.click()">
                                <x-reicon name="stop" class="size-4 text-error" />
                                Stop
                            </x-forms.button>
                        @else
                            <x-forms.button canGate="deploy" :canResource="$service"
                                @click="$wire.dispatch('startEvent')">
                                <x-reicon name="play-circle" class="size-4 text-warning" />
                                Deploy
                            </x-forms.button>
                        @endif
                    @else
                        <x-status-badge status="Required variables missing" type="error" />
                    @endif
                </div>
            </div>
        </div>

        <div class="hidden lg:block lg:h-12" aria-hidden="true"></div>
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
                const isDeploymentProgress = await $wire.$call('checkDeployments');

                if (isDeploymentProgress) {
                    $wire.$dispatch('error',
                        'There is a deployment in progress.<br><br>You can force deploy in the Advanced section.');
                    return;
                }

                $wire.$call('start');
            });
            $wire.$on('restartEvent', async () => {
                const isDeploymentProgress = await $wire.$call('checkDeployments');

                if (isDeploymentProgress) {
                    $wire.$dispatch('error',
                        'There is a deployment in progress.<br><br>You can force deploy in the Advanced section.');
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
