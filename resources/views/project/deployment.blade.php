<x-layout>
    <h1>Deployment</h1>
    <p>Name: {{ $project->name }}</p>
    <p>UUID: {{ $project->uuid }}</p>
    
    <p>Deployment UUID: {{ $deployment->uuid }}</p>
    <livewire:poll-activity :activity_log_id="$deployment->activity_log_id" />
</x-layout>
