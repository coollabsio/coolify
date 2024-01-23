<div>
    <x-team.navbar />
    <div class="flex gap-2">
        <h2>Shared Variables</h2>
        <x-slide-over>
            <x-slot:title>New Shared Variable</x-slot:title>
            <x-slot:content>
                <livewire:project.shared.environment-variable.add  />
            </x-slot:content>
            <button @click="slideOverOpen=true"
                class="font-normal text-white normal-case border-none rounded btn btn-primary btn-sm no-animation">+ Add</button>
        </x-slide-over>
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
