<x-layout>
    <h1 class="pb-0">Deployments</h1>
    <div class="pb-10 text-sm breadcrumbs">
        <ul>
            <li><a
                    href="{{ route('project.show', ['project_uuid' => request()->route('project_uuid')]) }}">{{ request()->route('project_uuid') }}</a>
            </li>
            <li><a
                    href="{{ route('project.resources', ['environment_name' => request()->route('environment_name'), 'project_uuid' => request()->route('project_uuid')]) }}">{{ request()->route('environment_name') }}</a>
            </li>
            <li>{{ data_get($application, 'name') }}</li>
        </ul>
    </div>
    <x-applications.navbar :application="$application" />
    <div class="flex flex-col gap-2 pt-2">
        @forelse ($deployments as $deployment)
            <livewire:project.application.get-deployments :deployment_uuid="data_get($deployment->properties, 'type_uuid')" :created_at="data_get($deployment, 'created_at')" :status="data_get($deployment->properties, 'status')" />
        @empty
            <p>No deployments found.</p>
        @endforelse
    </div>
</x-layout>
