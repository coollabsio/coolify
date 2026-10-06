<div>
    @php
        $sectionTitles = [
            'general' => 'General',
            'environment-variables' => 'Environment Variables',
            'resource-limits' => 'Resource Limits',
            'deployments' => 'Deployments',
            'deployment' => 'Deployment',
            'logs' => 'Logs',
            'danger' => 'Danger Zone',
        ];
        $isRunning = $status === 'Running';
        $pollInterval = match (true) {
            $section === 'deployment' && $selectedDeployment['isActive'] => '2000ms',
            in_array($section, ['general', 'deployments', 'deployment'], true) => '10000ms',
            default => null,
        };
    @endphp

    <livewire:project.shared.configuration-checker :resource="$workload"
        wire:key="cluster-application-configuration-checker-{{ $workload->uuid }}" />

    <x-slot:title>
        {{ str($workload->name)->limit(10) }} > {{ $sectionTitles[$section] }} | Coolify
    </x-slot>

    <nav wire:key="cluster-application-header-{{ $pollInterval ?? 'static' }}" class="w-full max-w-none pb-4 md:pb-6 lg:pb-0"
        @if ($pollInterval) wire:poll.{{ $pollInterval }}="refresh" @endif>
        <div class="mb-3 flex min-w-0 flex-col items-start gap-2 xl:hidden">
            <h1 class="min-w-0 max-w-full truncate text-[24px]! leading-7! font-semibold! tracking-tight!">
                {{ $workload->name }}
            </h1>
            <div class="relative flex w-full min-w-0 items-center gap-2">
                <x-status-summary :status="strtolower($status)" />
                <x-cluster-applications.links :workload="$workload" compact />
                @if ($this->runningDeploymentUrl)
                    <x-deploying-indicator :href="$this->runningDeploymentUrl" />
                @endif
            </div>
        </div>
        <div class="w-full xl:hidden">
            @can('update', $workload)
                <x-cluster-applications.deployment-actions id="cluster-application-mobile-actions"
                    :is-running="$isRunning" :container-present="$containerPresent" class="mb-3 flex w-full" />
            @endcan
            {{-- Keyed by state: the wire:ignore confirmations must render again when the state changes. --}}
            <div class="hidden" aria-hidden="true" wire:key="cluster-application-confirmations-{{ $isRunning ? 'running' : 'exited' }}">
                <x-modal-confirmation canGate="update" :canResource="$workload"
                    title="{{ $isRunning ? 'Confirm Application Stopping?' : 'Confirm Container Removal?' }}"
                    buttonTitle="{{ $isRunning ? 'Stop' : 'Remove container' }}"
                    submitAction="{{ $isRunning ? 'stop' : 'removeContainer' }}" :actions="$isRunning
                        ? ['This application will be stopped.', 'Its container is stopped on every server it runs on.']
                        : ['The exited application container will be removed.', 'The container is removed on every server it runs on. Deploy the application to create it again.']"
                    :confirmWithText="false" :confirmWithPassword="false"
                    step1ButtonText="Continue" step2ButtonText="Confirm">
                    <x-slot:trigger>
                        <button id="cluster-application-stop-trigger" type="button">Stop</button>
                    </x-slot:trigger>
                </x-modal-confirmation>
                <x-modal-confirmation canGate="update" :canResource="$workload"
                    title="Confirm Application Restart?" buttonTitle="Restart"
                    submitAction="restart" :actions="[
                        'This application will be restarted without rebuilding.',
                    ]" :confirmWithText="false" :confirmWithPassword="false"
                    step2ButtonText="Confirm">
                    <x-slot:trigger>
                        <button id="cluster-application-restart-trigger" type="button">Restart</button>
                    </x-slot:trigger>
                </x-modal-confirmation>
            </div>
        </div>
        {{-- Desktop actions live in the fixed top bar so they cannot overlap page content. --}}
        @teleport('#resource-action-hud-slot')
            <div class="hidden w-full items-center xl:flex xl:w-auto">
                <div class="resource-heading-navbar application-heading-actions flex w-full min-w-0 items-center justify-start gap-1 overflow-visible xl:w-auto xl:justify-end">
                    <div class="resource-heading-actions flex shrink-0 items-center gap-0.5">
                        @if ($this->runningDeploymentUrl)
                            <x-deploying-indicator :href="$this->runningDeploymentUrl" class="mr-1" />
                        @endif
                        <div class="resource-heading-menus shrink-0">
                            <x-cluster-applications.links :workload="$workload" />
                        </div>
                        @can('update', $workload)
                            <x-cluster-applications.deployment-actions id="cluster-application-desktop-actions"
                                :is-running="$isRunning" :container-present="$containerPresent" />
                        @endcan
                    </div>
                </div>
            </div>
        @endteleport
    </nav>

    <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
            <x-cluster-applications.sidebar :section="$section" :route-parameters="$routeParameters" />

            <div class="application-settings-form flex min-w-0 flex-col gap-6">
                @include('livewire.project.cluster-application.partials.'.$section)
            </div>
        </div>
    </section>
</div>
