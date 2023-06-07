<x-layout>
    <h1 class="py-0">Deployment</h1>
    <nav class="flex pt-2 pb-10 text-sm">
        <ol class="inline-flex items-center">
            <li class="inline-flex items-center">
                <a
                    href="{{ route('project.show', ['project_uuid' => request()->route('project_uuid')]) }}">{{ $application->environment->project->name }}</a>
            </li>
            <li>
                <div class="flex items-center">
                    <svg aria-hidden="true" class="w-4 h-4 mx-1 font-bold text-warning" fill="currentColor"
                        viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd"
                            d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                            clip-rule="evenodd"></path>
                    </svg>
                    <a
                        href="{{ route('project.resources', ['environment_name' => request()->route('environment_name'), 'project_uuid' => request()->route('project_uuid')]) }}">{{ request()->route('environment_name') }}</a>
                </div>
            </li>
            <li>
                <div class="flex items-center">
                    <svg aria-hidden="true" class="w-4 h-4 mx-1 font-bold text-warning" fill="currentColor"
                        viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd"
                            d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                            clip-rule="evenodd"></path>
                    </svg>
                    <span>{{ data_get($application, 'name') }}</span>
                </div>
            </li>
            <li>
                <div class="flex items-center">
                    <svg aria-hidden="true" class="w-4 h-4 mx-1 font-bold text-warning" fill="currentColor"
                        viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                        <path fill-rule="evenodd"
                            d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                            clip-rule="evenodd"></path>
                    </svg>
                    <span>
                        <livewire:project.application.status :application="$application" />
                    </span>
                </div>
            </li>
        </ol>
    </nav>
    <x-applications.navbar :application="$application" />
    <livewire:project.application.deployment-logs :activity="$activity" :application="$application" :deployment_uuid="$deployment_uuid" />
</x-layout>
