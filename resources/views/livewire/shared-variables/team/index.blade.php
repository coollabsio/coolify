<div>
    <x-slot:title>
        Team Variables | Coolify
    </x-slot>
    <div class="flex gap-2 items-center">
        <h1>{{ __('shared.team_shared_variables') }}</h1>
        @can('create', App\Models\SharedEnvironmentVariable::class)
            <x-modal-input buttonTitle="{{ __('button.add') }}" title="{{ __('modal.new_shared_variable') }}">
                <livewire:project.shared.environment-variable.add :shared="true" />
            </x-modal-input>
        @endcan
        <x-forms.button canGate="update" :canResource="$team" wire:click='switch'>{{ $view === 'normal' ? __('common.developer_view') : __('common.normal_view') }}</x-forms.button>
    </div>
    <div class="flex items-center gap-1 subtitle">{{ __('shared.you_can_use_variables') }} <span
            class="dark:text-warning text-coollabs">@{{ team.VARIABLENAME }}</span> <x-helper
            helper="{{ __('shared.more_info_here') }}"></x-helper>
    </div>

    @if ($view === 'normal')
        <div class="flex flex-col gap-2">
            @forelse ($team->environment_variables->sort()->sortBy('key') as $env)
                <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}"
                    :env="$env" type="team" />
            @empty
                <div>{{ __('shared.no_env_vars_found') }}</div>
            @endforelse
        </div>
    @else
        <form wire:submit='submit' class="flex flex-col gap-2">
            <x-forms.textarea canGate="update" :canResource="$team" rows="20" class="whitespace-pre-wrap" id="variables" wire:model="variables"
                label="{{ __('shared.team_shared_variables') }}"></x-forms.textarea>
            <x-forms.button canGate="update" :canResource="$team" type="submit" class="btn btn-primary">{{ __('common.save_all_env_vars') }}</x-forms.button>
        </form>
    @endif
</div>
