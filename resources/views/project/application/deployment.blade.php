<x-layout>
    <x-applications.navbar :application="$application" />
    <h1 class="py-10">Deployment</h1>
    <livewire:project.application.poll-deployment :activity="$activity" :deployment_uuid="$deployment_uuid" />
</x-layout>
