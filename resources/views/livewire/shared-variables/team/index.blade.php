<div>
    <x-slot:title>
        Team Variables | Coolify
    </x-slot>
    <div class="flex gap-2">
        <h1>Team Shared Variables</h1>
        <x-modal-input buttonTitle="+ Add" title="New Shared Variable">
            <livewire:project.shared.environment-variable.add :shared="true" />
        </x-modal-input>
    </div>
    <div class="flex items-center gap-1 subtitle">You can use these variables anywhere with <span
            class="dark:text-warning text-coollabs">@{{ team.VARIABLENAME }}</span> <x-helper
            helper="More info <a class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/environment-variables#shared-variables' target='_blank'>here</a>."></x-helper>
    </div>

    <div class="flex flex-col gap-2">
        @forelse ($team->environment_variables->sort()->sortBy('key') as $env)
            <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}"
                :env="$env" type="team" />
        @empty
            <div>No environment variables found.</div>
        @endforelse
    </div>
</div>
