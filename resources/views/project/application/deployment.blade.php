<x-layout>
    <h1 class="py-0">Deployment</h1>
    <x-applications.navbar :application="$application" />
    <livewire:project.application.deployment-logs :activity="$activity" :application="$application" :deployment_uuid="$deployment_uuid" />
</x-layout>
