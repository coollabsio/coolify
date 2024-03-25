<div>
    <x-team.navbar />
    <div class="flex gap-2">
        <h2>Shared Variables</h2>
        <x-modal-input buttonTitle="+ Add" title="New Shared Variable">
            <livewire:project.shared.environment-variable.add />
        </x-modal-input>
    </div>
    <div class="flex items-center gap-2 pb-4">You can use these variables anywhere with <span
            class="dark:text-warning text-coollabs">@{{ team.VARIABLENAME }}</span> <x-helper
            helper="More info <a class='underline dark:text-white' href='https://coolify.io/docs/environment-variables#shared-variables' target='_blank'>here</a>."></x-helper>
    </div>

    <div class="flex flex-col gap-2">
        @forelse ($team->environment_variables->sort()->sortBy('real_value') as $env)
            <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}"
                :env="$env" type="team" />
        @empty
            <div>No environment variables found.</div>
        @endforelse
    </div>
</div>
