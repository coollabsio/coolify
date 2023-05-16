<x-layout>
    <x-applications.navbar :applicationId="$application->id" :gitBranchLocation="$application->gitBranchLocation" />
    <h1 class="py-10">Deployment</h1>
    <livewire:project.application.poll-deployment :activity="$activity" :deployment_uuid="$deployment_uuid" />
</x-layout>
