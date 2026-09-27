<div>
    @php
        $sectionTitles = [
            'general' => 'General',
            'environment-variables' => 'Environment Variables',
            'resource-limits' => 'Resource Limits',
            'deployments' => 'Deployments',
            'deployment' => 'Deployment',
        ];
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
            <div class="flex items-center gap-2">
                <x-status-summary :status="strtolower($status)" />
                <x-cluster-applications.links :workload="$workload" compact />
            </div>
        </div>
        @can('update', $workload)
            <div class="mb-3 w-full xl:hidden">
                <x-cluster-applications.deployment-actions id="cluster-application-mobile-actions"
                    :status="$status" class="flex w-full" />
            </div>
        @endcan
        @teleport('#resource-action-hud-slot')
            <div class="hidden items-center gap-1 xl:flex">
                <x-cluster-applications.links :workload="$workload" />
                @can('update', $workload)
                    <x-cluster-applications.deployment-actions id="cluster-application-desktop-actions"
                        :status="$status" />
                @endcan
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
