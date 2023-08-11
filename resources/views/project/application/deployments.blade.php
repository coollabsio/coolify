<x-layout>
    <h1>Deployments</h1>
    <livewire:project.application.heading :application="$application" />
    <livewire:project.application.deployments :application="$application" :deployments="$deployments" :deployments_count="$deployments_count" />
</x-layout>
