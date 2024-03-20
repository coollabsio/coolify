<div>
    <x-team.navbar />
    <div class="flex gap-2">
        <h2>Shared Variables</h2>
        <x-slide-over>
            <x-slot:title>New Shared Variable</x-slot:title>
            <x-slot:content>
                <livewire:project.shared.environment-variable.add />
            </x-slot:content>
            <button @click="slideOverOpen=true" class="button">+
                Add</button>
        </x-slide-over>
    </div>
    <div class="flex items-center gap-2 pb-4">You can use these variables anywhere with <span
            class="text-warning">@{{ team.VARIABLENAME }}</span> <x-helper
            helper="More info <a class='text-white underline' href='https://coolify.io/docs/environment-variables#shared-variables' target='_blank'>here</a>."></x-helper>
    </div>

    <div class="flex flex-col gap-2">
        @forelse ($team->environment_variables->sort()->sortBy('real_value') as $env)
            <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}"
                :env="$env" type="team" />
        @empty
            <div class="text-neutral-500">No environment variables found.</div>
        @endforelse
    </div>
</div>
