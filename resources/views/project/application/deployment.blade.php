<x-layout>
    <h1>Deployment</h1>
    <x-applications.navbar :applicationId="$application->id" :gitBranchLocation="$application->gitBranchLocation" />
    <livewire:project.application.poll-deployment :activity="$activity" :deployment_uuid="$deployment_uuid" />
</x-layout>
