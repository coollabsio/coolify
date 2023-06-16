<x-layout>
    <h1>Deployments</h1>
    <x-applications.navbar :application="$application" />
    <livewire:project.application.deployments :application_id="$application->id" />
</x-layout>
