<x-layout>
    <div class="flex items-center gap-2">
        <h1>Environments</h1>
        <x-forms.button class="btn" onclick="newEnvironment.showModal()">+ Add</x-forms.button>
        <livewire:project.add-environment :project="$project" />
        @if ($project->applications->count() === 0)
            <livewire:project.delete-project :project_id="$project->id" />
        @endif
    </div>
    <div class="text-xs truncate subtitle lg:text-sm">{{ $project->name }}</div>
    <div class="grid gap-2 lg:grid-cols-2">
        @forelse ($project->environments as $environment)
            <a class="justify-center box" href="{{ route('project.resources', [$project->uuid, $environment->name]) }}">
                {{ $environment->name }}
            </a>
        @empty
            <p>No environments found.</p>
        @endforelse
    </div>
</x-layout>
