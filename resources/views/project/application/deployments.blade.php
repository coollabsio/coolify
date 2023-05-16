<x-layout>
    <x-applications.navbar :application="$application" :gitBranchLocation="$application->gitBranchLocation" />
    <h1 class="py-10">Deployments</h1>
    <div class="pt-2">
        @forelse ($deployments as $deployment)
            <livewire:project.application.get-deployments :deployment_uuid="data_get($deployment->properties, 'type_uuid')" :created_at="data_get($deployment, 'created_at')" :status="data_get($deployment->properties, 'status')" />
        @empty
            <p>No deployments found.</p>
        @endforelse
    </div>
</x-layout>
