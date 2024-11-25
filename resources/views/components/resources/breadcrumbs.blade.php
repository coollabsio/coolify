@props([
    'lastDeploymentInfo' => null,
    'lastDeploymentLink' => null,
    'resource' => null,
    'parameters' => []
])
<nav class="flex pt-2 pb-10">
    <ol class="flex flex-wrap items-center gap-y-1">
        <!-- Project Name Breadcrumb -->
        <li class="inline-flex items-center">
            <div class="flex items-center">
                <a class="text-xs truncate lg:text-sm"
                    href="{{ route('project.show', ['project_uuid' => data_get($parameters, 'project_uuid')]) }}">
                    {{ data_get($resource, 'environment.project.name', 'Undefined Name') }}</a>
                <!-- Dropdown for Project Resources -->
                @if($resource?->environment?->project?->resources?->isNotEmpty())
                <x-dropdown>
                    <x-slot:trigger>
                        <svg aria-hidden="true" class="w-4 h-4 mx-1 font-bold cursor-pointer dark:text-warning hover:scale-110" fill="currentColor"
                            viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd"
                                d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                                clip-rule="evenodd"></path>
                        </svg>
                    </x-slot:trigger>
                    <div class="flex flex-col gap-1">
                        @foreach(($resource?->environment?->project?->resources) ?? [] as $projectResource)
                            @if(method_exists($projectResource, 'link'))
                            <a href="{{ $projectResource->link() }}" class="dropdown-item">
                                {{ $projectResource->name }}
                            </a>
                            @endif
                        @endforeach
                    </div>
                </x-dropdown>
                @endif
            </div>
        </li>
        <!-- Environment Name Breadcrumb -->
        @if(data_get($parameters, 'environment_name'))
        <li>
            <div class="flex items-center">
                <a class="text-xs truncate lg:text-sm"
                    href="{{ route('project.resource.index', [
                        'environment_name' => data_get($parameters, 'environment_name'),
                        'project_uuid' => data_get($parameters, 'project_uuid')
                    ]) }}">
                    {{ data_get($parameters, 'environment_name') }}</a>
                <!-- Dropdown for Environment Resources -->
                @if($resource?->environment?->resources?->isNotEmpty())
                <x-dropdown>
                    <x-slot:trigger>
                        <svg aria-hidden="true" class="w-4 h-4 mx-1 font-bold cursor-pointer dark:text-warning hover:scale-110" fill="currentColor"
                            viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                            <path fill-rule="evenodd"
                                d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                                clip-rule="evenodd"></path>
                        </svg>
                    </x-slot:trigger>
                    <div class="flex flex-col gap-1">
                        @foreach(($resource?->environment?->resources) ?? [] as $envResource)
                            @if(method_exists($envResource, 'link'))
                            <a href="{{ $envResource->link() }}" class="dropdown-item">
                                {{ $envResource->name }}
                            </a>
                            @endif
                        @endforeach
                    </div>
                </x-dropdown>
                @endif
            </div>
        </li>
        @endif
        <!-- Resource Name Breadcrumb -->
        @if($resource)
        <li>
            <div class="flex items-center">
                <span class="text-xs truncate lg:text-sm">{{ data_get($resource, 'name') }}</span>
                <!-- Dropdown for Resource Actions -->
                @if($resource->getMorphClass() !== 'App\Models\Service')
                    <x-dropdown>
                        <x-slot:trigger>
                            <svg aria-hidden="true" class="w-4 h-4 mx-1 font-bold cursor-pointer dark:text-warning hover:scale-110" fill="currentColor"
                                viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                                <path fill-rule="evenodd"
                                    d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                                    clip-rule="evenodd"></path>
                            </svg>
                        </x-slot:trigger>
                        <div class="flex flex-col gap-1">
                            <a href="{{ route('project.application.configuration', $parameters) }}" class="dropdown-item">Configuration</a>
                            <a href="{{ route('project.application.deployment.index', $parameters) }}" class="dropdown-item">Deployments</a>
                            <a href="{{ route('project.application.logs', $parameters) }}" class="dropdown-item">Logs</a>
                            @if ($resource->destination?->server && !$resource->destination->server->isSwarm())
                                <a href="{{ route('project.application.command', $parameters) }}" class="dropdown-item">Terminal</a>
                            @endif
                        </div>
                    </x-dropdown>
                @endif
            </div>
        </li>
        @endif
        <!-- Status Component on Resource Type -->
        @if ($resource && $resource->getMorphClass() == 'App\Models\Service')
            <x-status.services :service="$resource" />
        @else
            <x-status.index :resource="$resource" :title="$lastDeploymentInfo" :lastDeploymentLink="$lastDeploymentLink" />
        @endif
    </ol>
</nav>
