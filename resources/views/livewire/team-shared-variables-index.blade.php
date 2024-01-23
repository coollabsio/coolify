<div>
    <x-team.navbar />
    <div class="flex gap-2">
        <h2>Shared Variables</h2>
        <x-forms.button class="btn" onclick="newVariable.showModal()">+ Add</x-forms.button>
        <livewire:project.shared.environment-variable.add />
    </div>
    <div class="pb-4">You can use this anywhere.</div>
    <div class="flex flex-col gap-2">
        @forelse ($team->environment_variables->sort()->sortBy('real_value') as $env)
            <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}"
                :env="$env" type="team" />
        @empty
            <div class="text-neutral-500">No environment variables found.</div>
        @endforelse
    </div>
</div>
