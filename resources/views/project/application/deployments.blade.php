<x-layout>
    <h1 class="pb-0">Deployments</h1>
    <div class="pb-10 text-sm breadcrumbs">
        <ul>
            <li><a
                    href="{{ route('project.show', ['project_uuid' => request()->route('project_uuid')]) }}">{{ $application->environment->project->name }}</a>
            </li>
            <li><a
                    href="{{ route('project.resources', ['environment_name' => request()->route('environment_name'), 'project_uuid' => request()->route('project_uuid')]) }}">{{ request()->route('environment_name') }}</a>
            </li>
            <li>{{ data_get($application, 'name') }}</li>
        </ul>
    </div>
    <x-applications.navbar :application="$application" />
    <livewire:project.application.deployments :application_id="$application->id" />
</x-layout>
