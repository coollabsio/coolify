<x-layout>
    <h1>Application</h1>
    <p>Name: {{ $project->name }}</p>
    <p>UUID: {{ $project->uuid }}</p>
    <livewire:deploy-application :application_uuid="$application->uuid" />
    <div>
        <h1>Deployments</h1>
        @foreach ($deployments as $deployment)
            <p>
                <a href="{{ url()->current() }}/deployment/{{ data_get($deployment->properties, 'deployment_uuid') }}">
                    {{ data_get($deployment->properties, 'deployment_uuid') }}</a>
            </p>
        @endforeach
    </div>
</x-layout>
