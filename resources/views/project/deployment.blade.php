<x-layout>
    <h1>Deployment</h1>
    <p>Name: {{ $project->name }}</p>
    <p>UUID: {{ $project->uuid }}</p>

    <livewire:poll-activity :activity="$activity" />
</x-layout>
