<div>
    @php
        $sectionTitles = [
            'general' => 'Configuration',
            'domains' => 'Domains',
            'environment-variables' => 'Environment Variables',
            'storages' => 'Persistent Storage',
            'backups' => 'Backups',
            'logs' => 'Logs',
            'terminal' => 'Terminal',
            'scheduled-tasks' => 'Scheduled Tasks',
            'webhooks' => 'Webhooks',
            'deployments' => 'Deployments',
            'danger' => 'Danger Zone',
        ];
        $isDeploying = in_array($stack->deploy_status, ['queued', 'deploying'], true);
        $links = collect($services)->flatMap(fn (array $service): array => array_map(fn (string $domain): array => ['service' => $service['service'], 'url' => $domain], $service['domains']));
    @endphp

    <x-slot:title>
        {{ str($stack->name)->limit(10) }} > {{ $sectionTitles[$section] }} | Coolify
    </x-slot>

    {{-- The heading of a v4 service: status, links, and Deploy / Restart / Stop. --}}
    <nav class="w-full max-w-none pb-4 md:pb-6 lg:pb-0" wire:poll.10000ms>
        <div class="mb-3 flex min-w-0 flex-col items-start gap-2 xl:hidden">
            <h1 class="min-w-0 max-w-full truncate text-[24px]! leading-7! font-semibold! tracking-tight!">{{ $stack->name }}</h1>
            <div class="relative flex w-full min-w-0 items-center gap-2">
                <x-status-summary :status="$status" title="Stack status" />
                @if ($isDeploying)
                    <x-deploying-indicator :href="route('project.cluster-stack.deployments', $routeParameters)" />
                @endif
            </div>
        </div>
        <div class="w-full xl:hidden">
            @can('deploy', $stack)
                <x-cluster-applications.deployment-actions id="cluster-stack-mobile-actions" trigger-prefix="cluster-stack"
                    :is-running="$isRunning" class="mb-3 flex w-full" />
            @endcan
        </div>
        @can('deploy', $stack)
            <div class="hidden" aria-hidden="true" wire:key="cluster-stack-confirmations-{{ $isRunning ? 'running' : 'exited' }}">
                <x-modal-confirmation canGate="deploy" :canResource="$stack" title="Confirm Stack Stopping?" buttonTitle="Stop"
                    submitAction="stop" :actions="['Every service of this stack will be stopped.']"
                    :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Confirm">
                    <x-slot:trigger>
                        <button id="cluster-stack-stop-trigger" type="button">Stop</button>
                    </x-slot:trigger>
                </x-modal-confirmation>
                <x-modal-confirmation canGate="deploy" :canResource="$stack" title="Confirm Stack Restart?" buttonTitle="Restart"
                    submitAction="restart" :actions="['Every service of this stack will be restarted without a new deploy.']"
                    :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Confirm">
                    <x-slot:trigger>
                        <button id="cluster-stack-restart-trigger" type="button">Restart</button>
                    </x-slot:trigger>
                </x-modal-confirmation>
            </div>
        @endcan
        {{-- Desktop actions live in the fixed top bar so they cannot overlap page content. --}}
        @teleport('#resource-action-hud-slot')
            <div class="hidden w-full items-center xl:flex xl:w-auto">
                <div class="resource-heading-navbar application-heading-actions flex w-full min-w-0 items-center justify-start gap-1 overflow-visible xl:w-auto xl:justify-end">
                    <div class="resource-heading-actions flex shrink-0 items-center gap-0.5">
                        @if ($isDeploying)
                            <x-deploying-indicator :href="route('project.cluster-stack.deployments', $routeParameters)" class="mr-1" />
                        @endif
                        @if ($links->isNotEmpty())
                            <div class="resource-heading-menus relative shrink-0" x-data="{ open: false }" @keydown.escape.window="open = false">
                                <button type="button" class="app-tab shrink-0 gap-1" @click="open = !open" @click.outside="open = false" title="Open stack links">
                                    <span class="inline-flex items-center gap-2">
                                        <x-reicon name="external-link" class="size-3.5 shrink-0 opacity-70" />
                                        Links
                                    </span>
                                    <x-reicon name="chevron-down" class="size-3 opacity-55" />
                                </button>
                                <div x-show="open" x-cloak x-transition.origin.top.right role="menu" class="listbox-panel top-full! mt-1! right-0! left-auto! min-w-72! max-w-96!">
                                    @foreach ($links as $link)
                                        <a href="{{ $link['url'] }}" target="_blank" rel="noopener noreferrer" class="listbox-option justify-start! gap-2.5!" role="menuitem">
                                            <x-reicon name="external-link" class="size-3.5 shrink-0 opacity-70" />
                                            <span class="min-w-0 truncate">{{ $link['url'] }}</span>
                                            <span class="ml-auto shrink-0 text-xs text-neutral-400 dark:text-fg-faint">{{ $link['service'] }}</span>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @can('deploy', $stack)
                            <x-cluster-applications.deployment-actions id="cluster-stack-desktop-actions" trigger-prefix="cluster-stack"
                                :is-running="$isRunning" />
                        @endcan
                    </div>
                </div>
            </div>
        @endteleport
    </nav>

    <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
            <x-cluster-stacks.sidebar :section="$section" :route-parameters="$routeParameters" :stack="$stack" />

            <div class="application-settings-form flex min-w-0 flex-col gap-6">
                @if ($stack->deploy_status === 'failed' && filled($stack->deploy_error))
                    <x-callout type="danger" title="The stack deployment failed">
                        {{ $stack->deploy_error }}
                    </x-callout>
                @elseif ($isDeploying)
                    <x-callout type="info" title="Deploying">
                        {{ $stack->hasGitSource() ? 'Each service with build: builds first. Then each service deploys after the services it depends on.' : 'Each service deploys after the services it depends on.' }}
                    </x-callout>
                @endif
                @foreach (array_filter($services, fn (array $service): bool => $service['mountsContainerSocket']) as $service)
                    <x-callout type="warning" title="The service {{ $service['service'] }} mounts the Docker socket">
                        This service mounts the Docker socket. It has full control of the server {{ $service['server'] ?? 'that it runs on' }}, including every container on it.
                    </x-callout>
                @endforeach
                @include('livewire.project.cluster-stack.partials.'.$section)
            </div>
        </div>
    </section>
</div>
