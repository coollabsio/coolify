<x-layout>
    <h1>Application</h1>
    <p>Name: {{ $application->name }}</p>
    <livewire:deploy-application :application_uuid="$application->uuid" />
    <div>
        <h2>Deployments</h2>
        @foreach ($deployments as $deployment)
            <p>
                <a href="{{ url()->current() }}/deployment/{{ data_get($deployment->properties, 'deployment_uuid') }}">
                    {{ data_get($deployment->properties, 'deployment_uuid') }}</a>
                {{ data_get($deployment->properties, 'status') }}
            </p>
        @endforeach
    </div>
</x-layout>
