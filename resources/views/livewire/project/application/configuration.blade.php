<div>
    <x-slot:title>
        {{ data_get_str($application, 'name')->limit(10) }} > Configuration | Coolify
    </x-slot>
    <h1>Configuration</h1>
    <livewire:project.shared.configuration-checker :resource="$application" />
    <livewire:project.application.heading :application="$application" />

    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <div class="sub-menu-wrapper">
            <a class='sub-menu-item' {{ wireNavigate() }} wire:current.exact="menu-item-active"
                href="{{ route('project.application.configuration', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'application_uuid' => $application->uuid]) }}"><span class="menu-item-label">General</span></a>
            <a class='sub-menu-item' {{ wireNavigate() }} wire:current.exact="menu-item-active"
                href="{{ route('project.application.advanced', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'application_uuid' => $application->uuid]) }}"><span class="menu-item-label">Advanced</span></a>
            @if ($application->destination->server->isSwarm())
                <a class="sub-menu-item" {{ wireNavigate() }} wire:current.exact="menu-item-active"
                    href="{{ route('project.application.swarm', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'application_uuid' => $application->uuid]) }}"><span class="menu-item-label">Swarm Configuration</span></a>
            @endif
            <a class='sub-menu-item' {{ wireNavigate() }} wire:current.exact="menu-item-active"
                href="{{ route('project.application.environment-variables', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'application_uuid' => $application->uuid]) }}"><span class="menu-item-label">Environment Variables</span></a>
            <a class='sub-menu-item' {{ wireNavigate() }} wire:current.exact="menu-item-active"
                href="{{ route('project.application.persistent-storage', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'application_uuid' => $application->uuid]) }}"><span class="menu-item-label">Persistent Storage</span></a>
            @if ($application->git_based())
                <a class='sub-menu-item' {{ wireNavigate() }} wire:current.exact="menu-item-active"
                    href="{{ route('project.application.source', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'application_uuid' => $application->uuid]) }}"><span class="menu-item-label">Git Source</span></a>
            @endif
            <a class="sub-menu-item flex items-center gap-2" {{ wireNavigate() }} wire:current.exact="menu-item-active"
                href="{{ route('project.application.servers', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'application_uuid' => $application->uuid]) }}"><span class="menu-item-label">Servers</span>
                @if ($application->server_status == false)
                    <span title="One or more servers are unreachable or misconfigured.">
                        <svg class="w-4 h-4 text-error" viewBox="0 0 256 256" xmlns="http://www.w3.org/2000/svg">
                            <path fill="currentColor"
                                d="M240.26 186.1L152.81 34.23a28.74 28.74 0 0 0-49.62 0L15.74 186.1a27.45 27.45 0 0 0 0 27.71A28.31 28.31 0 0 0 40.55 228h174.9a28.31 28.31 0 0 0 24.79-14.19a27.45 27.45 0 0 0 .02-27.71m-20.8 15.7a4.46 4.46 0 0 1-4 2.2H40.55a4.46 4.46 0 0 1-4-2.2a3.56 3.56 0 0 1 0-3.73L124 46.2a4.77 4.77 0 0 1 8 0l87.44 151.87a3.56 3.56 0 0 1 .02 3.73M116 136v-32a12 12 0 0 1 24 0v32a12 12 0 0 1-24 0m28 40a16 16 0 1 1-16-16a16 16 0 0 1 16 16" />
                        </svg>
                    </span>
                @elseif ($application->additional_servers()->exists() && str($application->status)->contains('degraded'))
                    <span title="Application is in degraded state across multiple servers.">
                        <svg class="w-4 h-4 text-error" viewBox="0 0 256 256" xmlns="http://www.w3.org/2000/svg">
                            <path fill="currentColor"
                                d="M240.26 186.1L152.81 34.23a28.74 28.74 0 0 0-49.62 0L15.74 186.1a27.45 27.45 0 0 0 0 27.71A28.31 28.31 0 0 0 40.55 228h174.9a28.31 28.31 0 0 0 24.79-14.19a27.45 27.45 0 0 0 .02-27.71m-20.8 15.7a4.46 4.46 0 0 1-4 2.2H40.55a4.46 4.46 0 0 1-4-2.2a3.56 3.56 0 0 1 0-3.73L124 46.2a4.77 4.77 0 0 1 8 0l87.44 151.87a3.56 3.56 0 0 1 .02 3.73M116 136v-32a12 12 0 0 1 24 0v32a12 12 0 0 1-24 0m28 40a16 16 0 1 1-16-16a16 16 0 0 1 16 16" />
                        </svg>
                    </span>
                @endif
            </a>
            <a class="sub-menu-item" {{ wireNavigate() }} wire:current.exact="menu-item-active"
                href="{{ route('project.application.scheduled-tasks.show', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'application_uuid' => $application->uuid]) }}"><span class="menu-item-label">Scheduled Tasks</span></a>
            <a class="sub-menu-item" {{ wireNavigate() }} wire:current.exact="menu-item-active"
                href="{{ route('project.application.webhooks', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'application_uuid' => $application->uuid]) }}"><span class="menu-item-label">Webhooks</span></a>
            @if ($application->deploymentType() !== 'deploy_key')
                <a class="sub-menu-item" {{ wireNavigate() }} wire:current.exact="menu-item-active"
                    href="{{ route('project.application.preview-deployments', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'application_uuid' => $application->uuid]) }}"><span class="menu-item-label">Preview Deployments</span></a>
            @endif
            @if ($application->build_pack !== 'dockercompose')
                <a class="sub-menu-item" {{ wireNavigate() }} wire:current.exact="menu-item-active"
                    href="{{ route('project.application.healthcheck', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'application_uuid' => $application->uuid]) }}"><span class="menu-item-label">Healthcheck</span></a>
            @endif
            <a class="sub-menu-item" {{ wireNavigate() }} wire:current.exact="menu-item-active"
                href="{{ route('project.application.rollback', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'application_uuid' => $application->uuid]) }}"><span class="menu-item-label">Rollback</span></a>
            <a class="sub-menu-item" {{ wireNavigate() }} wire:current.exact="menu-item-active"
                href="{{ route('project.application.resource-limits', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'application_uuid' => $application->uuid]) }}"><span class="menu-item-label">Resource Limits</span></a>
            <a class="sub-menu-item" {{ wireNavigate() }} wire:current.exact="menu-item-active"
                href="{{ route('project.application.resource-operations', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'application_uuid' => $application->uuid]) }}"><span class="menu-item-label">Resource Operations</span></a>
            <a class="sub-menu-item" {{ wireNavigate() }} wire:current.exact="menu-item-active"
                href="{{ route('project.application.metrics', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'application_uuid' => $application->uuid]) }}"><span class="menu-item-label">Metrics</span></a>
            <a class="sub-menu-item" {{ wireNavigate() }} wire:current.exact="menu-item-active"
                href="{{ route('project.application.tags', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'application_uuid' => $application->uuid]) }}"><span class="menu-item-label">Tags</span></a>
            <a class="sub-menu-item" {{ wireNavigate() }} wire:current.exact="menu-item-active"
                href="{{ route('project.application.danger', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'application_uuid' => $application->uuid]) }}"><span class="menu-item-label">Danger Zone</span></a>
        </div>
        <div class="w-full sm:flex-grow">
            @if ($currentRoute === 'project.application.configuration')
                <livewire:project.application.general :application="$application" />
            @elseif ($currentRoute === 'project.application.swarm' && $application->destination->server->isSwarm())
                <livewire:project.application.swarm :application="$application" />
            @elseif ($currentRoute === 'project.application.advanced')
                <livewire:project.application.advanced :application="$application" />
            @elseif ($currentRoute === 'project.application.environment-variables')
                <livewire:project.shared.environment-variable.all :resource="$application" />
            @elseif ($currentRoute === 'project.application.persistent-storage')
                <livewire:project.service.storage :resource="$application" />
            @elseif ($currentRoute === 'project.application.source' && $application->git_based())
                <livewire:project.application.source :application="$application" />
            @elseif ($currentRoute === 'project.application.servers')
                <livewire:project.shared.destination :resource="$application" />
            @elseif ($currentRoute === 'project.application.scheduled-tasks.show')
                <livewire:project.shared.scheduled-task.all :resource="$application" />
            @elseif ($currentRoute === 'project.application.webhooks')
                <livewire:project.shared.webhooks :resource="$application" />
            @elseif ($currentRoute === 'project.application.preview-deployments')
                <livewire:project.application.previews :application="$application" />
            @elseif ($currentRoute === 'project.application.healthcheck' && $application->build_pack !== 'dockercompose')
                <livewire:project.shared.health-checks :resource="$application" />
            @elseif ($currentRoute === 'project.application.rollback')
                <livewire:project.application.rollback :application="$application" />
            @elseif ($currentRoute === 'project.application.resource-limits')
                <livewire:project.shared.resource-limits :resource="$application" />
            @elseif ($currentRoute === 'project.application.resource-operations')
                <livewire:project.shared.resource-operations :resource="$application" />
            @elseif ($currentRoute === 'project.application.metrics')
                <livewire:project.shared.metrics :resource="$application" />
            @elseif ($currentRoute === 'project.application.tags')
                <livewire:project.shared.tags :resource="$application" />
            @elseif ($currentRoute === 'project.application.danger')
                <livewire:project.shared.danger :resource="$application" />
            @endif
        </div>
    </div>
</div>
