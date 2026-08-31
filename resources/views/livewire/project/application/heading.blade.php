<nav wire:poll.10000ms="checkStatus" class="w-full max-w-none pb-4 md:pb-6 lg:pb-0">
    @php
        $routeIs = fn (string|array $routes): bool => \Illuminate\Support\Str::is($routes, $activeRouteName);
        // Settings covers all configuration sub-pages (General, Webhooks, Domains, …),
        // not only project.application.configuration. Primary tabs that are NOT settings:
        // backups, console, deployment logs, runtime logs.
        $isSettingsRoute = $routeIs('project.application.*')
            && ! $routeIs([
                'project.application.backup.*',
                'project.application.command',
                'project.application.deployment.*',
                'project.application.logs',
            ]);
        $applicationMenuItems = [
            [
                'label' => 'Settings',
                'route' => 'project.application.configuration',
                'active' => $isSettingsRoute,
            ],
        ];

        $applicationMenuItems = array_values(array_filter(
            $applicationMenuItems,
            fn (array $item): bool => $item['visible'] ?? true,
        ));
    @endphp
    <div>
        <div class="mb-3 w-full xl:hidden">
            <div class="flex min-w-0 flex-col items-start gap-2">
                <h1 class="min-w-0 max-w-full truncate text-[24px]! leading-7! font-semibold! tracking-tight! text-black dark:text-fg">
                    {{ $application->name }}
                </h1>
                <div class="relative flex w-full min-w-0 items-center gap-2">
                    <x-status-summary :status="$application->status" />
                    <x-applications.links :application="$application" compact />
                </div>
            </div>
        </div>

        <div class="w-full xl:hidden">
            @if (!($application->build_pack === 'dockercompose' && is_null($application->docker_compose_raw)))
                @can('deploy', $application)
                    <x-split-action id="application-mobile-actions" class="mb-3 flex w-full">
                        @if (str($application->status)->startsWith('exited'))
                            <x-slot:main wire:click="deploy">
                                <x-reicon name="play-circle" class="size-3.5" />
                                Deploy
                            </x-slot:main>
                            @if (!$application->destination->server->isSwarm())
                                <button type="button" class="listbox-option justify-start! gap-2.5!" wire:click="deploy(true)"
                                    @click="open = false" role="menuitem">
                                    <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                    Deploy (without cache)
                                </button>
                            @endif
                        @else
                            @if ($application->destination->server->isSwarm())
                                @if ($application->build_pack !== 'dockercompose')
                                    <x-slot:main wire:click="deploy">
                                        <x-reicon name="refresh" class="size-3.5" />
                                        Update Service
                                    </x-slot:main>
                                @else
                                    <x-slot:main @click="document.getElementById('application-mobile-stop-trigger')?.click()">
                                        <x-reicon name="stop-circle" class="size-3.5" />
                                        Stop
                                    </x-slot:main>
                                @endif
                            @else
                                <x-slot:main wire:click="deploy">
                                    <x-reicon name="refresh" class="size-3.5" />
                                    Redeploy
                                </x-slot:main>
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    wire:click="{{ str($application->status)->startsWith('running') ? 'force_deploy_without_cache' : 'deploy(true)' }}"
                                    @click="open = false" role="menuitem">
                                    <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                    {{ str($application->status)->startsWith('running') ? 'Redeploy (without cache)' : 'Deploy (without cache)' }}
                                </button>
                                @if ($application->build_pack !== 'dockercompose')
                                    <button type="button" class="listbox-option justify-start! gap-2.5!"
                                        @click="open = false; document.getElementById('application-mobile-restart-trigger')?.click()"
                                        role="menuitem">
                                        <x-reicon name="restart" class="size-3.5 opacity-70" />
                                        Restart
                                    </button>
                                @endif
                            @endif
                            @unless ($application->destination->server->isSwarm() && $application->build_pack === 'dockercompose')
                                <button type="button" class="listbox-option justify-start! gap-2.5!"
                                    @click="open = false; document.getElementById('application-mobile-stop-trigger')?.click()"
                                    role="menuitem">
                                    <x-reicon name="stop-circle" class="size-3.5 text-error" />
                                    Stop
                                </button>
                            @endunless
                        @endif
                    </x-split-action>
                @endcan
            @endif
            <div class="hidden" aria-hidden="true">
                <x-modal-confirmation title="Confirm Application Stopping?" buttonTitle="Stop"
                    submitAction="stop" :checkboxes="$checkboxes" :actions="[
                        'This application will be stopped.',
                        'All non-persistent data of this application will be deleted.',
                    ]" :confirmWithText="false" :confirmWithPassword="false"
                    step1ButtonText="Continue" step2ButtonText="Confirm">
                    <x-slot:trigger>
                        <button id="application-mobile-stop-trigger" type="button">Stop</button>
                    </x-slot:trigger>
                </x-modal-confirmation>
                <x-modal-confirmation title="Confirm Application Restart?" buttonTitle="Restart"
                    submitAction="restart" :actions="[
                        'This application will be restarted without rebuilding.',
                    ]" :confirmWithText="false" :confirmWithPassword="false"
                    step2ButtonText="Confirm">
                    <x-slot:trigger>
                        <button id="application-mobile-restart-trigger" type="button">Restart</button>
                    </x-slot:trigger>
                </x-modal-confirmation>
            </div>
        </div>

        {{-- Desktop actions live in the fixed top bar so they cannot overlap page content. --}}
        @teleport('#resource-action-hud-slot')
        <div class="hidden w-full items-center xl:flex xl:w-auto">
            <div
                class="resource-heading-navbar application-heading-actions flex w-full min-w-0 items-center justify-start gap-1 overflow-visible xl:w-auto xl:justify-end">
                <div class="resource-heading-actions flex shrink-0 items-center gap-0.5">
                    @if ($application->build_pack === 'dockercompose' && is_null($application->docker_compose_raw))
                        <span class="px-2 text-[13px] text-neutral-500 dark:text-fg-dim">Load a Compose file to deploy.</span>
                    @else
                        <div class="resource-heading-menus shrink-0">
                            <x-applications.links :application="$application" />
                        </div>
                        @can('deploy', $application)
                            <x-split-action id="application-desktop-actions">
                                @if (str($application->status)->startsWith('exited'))
                                    <x-slot:main wire:click="deploy">
                                        <x-reicon name="play-circle" class="size-3.5" />
                                        Deploy
                                    </x-slot:main>
                                    @if (!$application->destination->server->isSwarm())
                                        <button type="button" class="listbox-option justify-start! gap-2.5!" wire:click="deploy(true)"
                                            @click="open = false" role="menuitem">
                                            <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                            Deploy (without cache)
                                        </button>
                                    @endif
                                @else
                                    @if ($application->destination->server->isSwarm())
                                        @if ($application->build_pack !== 'dockercompose')
                                            <x-slot:main wire:click="deploy">
                                                <x-reicon name="refresh" class="size-3.5" />
                                                Update Service
                                            </x-slot:main>
                                        @else
                                            <x-slot:main @click="document.getElementById('application-mobile-stop-trigger')?.click()">
                                                <x-reicon name="stop-circle" class="size-3.5" />
                                                Stop
                                            </x-slot:main>
                                        @endif
                                    @else
                                        <x-slot:main wire:click="deploy">
                                            <x-reicon name="refresh" class="size-3.5" />
                                            Redeploy
                                        </x-slot:main>
                                        <button type="button" class="listbox-option justify-start! gap-2.5!"
                                            wire:click="{{ str($application->status)->startsWith('running') ? 'force_deploy_without_cache' : 'deploy(true)' }}"
                                            @click="open = false" role="menuitem">
                                            <x-reicon name="refresh" class="size-3.5 opacity-70" />
                                            {{ str($application->status)->startsWith('running') ? 'Redeploy (without cache)' : 'Deploy (without cache)' }}
                                        </button>
                                        @if ($application->build_pack !== 'dockercompose')
                                            <button type="button" class="listbox-option justify-start! gap-2.5!"
                                                @click="open = false; document.getElementById('application-mobile-restart-trigger')?.click()"
                                                role="menuitem">
                                                <x-reicon name="restart" class="size-3.5 opacity-70" />
                                                Restart
                                            </button>
                                        @endif
                                    @endif
                                    @unless ($application->destination->server->isSwarm() && $application->build_pack === 'dockercompose')
                                        <button type="button" class="listbox-option justify-start! gap-2.5!"
                                            @click="open = false; document.getElementById('application-mobile-stop-trigger')?.click()"
                                            role="menuitem">
                                            <x-reicon name="stop-circle" class="size-3.5 text-error" />
                                            Stop
                                        </button>
                                    @endunless
                                @endif
                            </x-split-action>
                        @endcan
                    @endif
                </div>
            </div>
        </div>
        @endteleport
    </div>
</nav>
