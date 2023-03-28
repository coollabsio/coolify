<x-layout>
    <h1>Application</h1>
    <p>Name: {{ $project->name }}</p>
    <p>UUID: {{ $project->uuid }}</p>
    <livewire:deploy-application :application_uuid="$application->uuid" />
</x-layout>
