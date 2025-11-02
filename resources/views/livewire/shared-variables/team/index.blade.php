<div>
    <x-slot:title>
        Team Variables | Coolify
    </x-slot>
    <div class="flex gap-2 items-center">
        <h1>Team Shared Variables</h1>
        @can('create', App\Models\SharedEnvironmentVariable::class)
            <x-modal-input buttonTitle="+ Add" title="New Shared Variable">
                <livewire:project.shared.environment-variable.add :shared="true" />
            </x-modal-input>
        @endcan
        @can('update', $team)
            <x-forms.button wire:click='switch'>{{ $view === 'normal' ? 'Developer view' : 'Normal view' }}</x-forms.button>
        @endcan
    </div>
    <div class="flex items-center gap-1 subtitle">You can use these variables anywhere with <span
            class="dark:text-warning text-coollabs">@{{ team.VARIABLENAME }}</span> <x-helper
            helper="More info <a class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/environment-variables#shared-variables' target='_blank'>here</a>."></x-helper>
    </div>

    @if ($view === 'normal')
        <div class="flex flex-col gap-2">
            @forelse ($team->environment_variables->sort()->sortBy('key') as $env)
                <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}"
                    :env="$env" type="team" />
            @empty
                <div>No environment variables found.</div>
            @endforelse
        </div>
    @else
        <form wire:submit='submit' class="flex flex-col gap-2">
            @can('update', $team)
                <x-forms.textarea rows="20" class="whitespace-pre-wrap" id="variables" wire:model="variables"
                    label="Team Shared Variables"></x-forms.textarea>
                <x-forms.button type="submit" class="btn btn-primary">Save All Environment Variables</x-forms.button>
            @else
                <x-forms.textarea rows="20" class="whitespace-pre-wrap" id="variables" wire:model="variables"
                    label="Team Shared Variables" disabled></x-forms.textarea>
            @endcan
        </form>
    @endif
</div>
