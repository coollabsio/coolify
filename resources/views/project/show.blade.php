<x-layout>
    <div class="flex items-center gap-2">
        <h1>Environments</h1>
        <x-forms.button class="btn" onclick="newEnvironment.showModal()">+ Add</x-forms.button>
        <livewire:project.add-environment :project="$project" />
    </div>
    <div class="pt-2 pb-10 text-xs truncate lg:text-sm">{{ $project->name }}</div>
    <div class="grid gap-2 lg:grid-cols-2">
        @forelse ($project->environments as $environment)
            <a class="box" href="{{ route('project.resources', [$project->uuid, $environment->name]) }}">
                {{ $environment->name }}
            </a>
        @empty
            <p>No environments found.</p>
        @endforelse
    </div>
</x-layout>
