@props([
    'lastDeploymentInfo' => null,
    'lastDeploymentLink' => null,
    'resource' => null,
])
<nav class="flex pt-2 pb-10">
    <ol class="flex flex-wrap items-center gap-y-1">
        <li class="inline-flex items-center">
            <div class="flex items-center">
                <a class="text-xs truncate lg:text-sm"
                    href="{{ route('project.show', ['project_uuid' => data_get($resource, 'environment.project.uuid')]) }}">
                    {{ data_get($resource, 'environment.project.name', 'Undefined Name') }}</a>
                <svg aria-hidden="true" class="w-4 h-4 mx-1 font-bold dark:text-warning" fill="currentColor"
                    viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd"
                        d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                        clip-rule="evenodd"></path>
                </svg>
            </div>
        </li>
        <li>
            <div class="flex items-center">
                <a class="text-xs truncate lg:text-sm"
                    href="{{ route('project.resource.index', ['environment_name' => data_get($resource, 'environment.name'), 'project_uuid' => data_get($resource, 'environment.project.uuid')]) }}">{{ data_get($resource, 'environment.name') }}</a>
                <svg aria-hidden="true" class="w-4 h-4 mx-1 font-bold dark:text-warning" fill="currentColor"
                    viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd"
                        d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                        clip-rule="evenodd"></path>
                </svg>
            </div>
        </li>
        <li>
            <div class="flex items-center">
                <span class="text-xs truncate lg:text-sm">{{ data_get($resource, 'name') }}</span>
                <svg aria-hidden="true" class="w-4 h-4 mx-1 font-bold dark:text-warning" fill="currentColor"
                    viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                    <path fill-rule="evenodd"
                        d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                        clip-rule="evenodd"></path>
                </svg>
            </div>
        </li>
        @if ($resource->getMorphClass() == 'App\Models\Service')
            <x-status.services :service="$resource" />
        @else
            <x-status.index :resource="$resource" :title="$lastDeploymentInfo" :lastDeploymentLink="$lastDeploymentLink" />
        @endif
    </ol>
</nav>
