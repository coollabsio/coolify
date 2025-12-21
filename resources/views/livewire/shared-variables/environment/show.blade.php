<div>
    <x-slot:title>
        Environment Variable | Coolify
    </x-slot>
    <div class="flex gap-2">
        <h1>{{ __('shared.shared_variables_for') }} {{ $project->name }}/{{ $environment->name }}</h1>
        @can('update', $environment)
            <x-modal-input buttonTitle="{{ __('button.add') }}" title="{{ __('modal.new_shared_variable') }}">
                <livewire:project.shared.environment-variable.add :shared="true" />
            </x-modal-input>
        @endcan
        <x-forms.button canGate="update" :canResource="$environment" wire:click='switch'>{{ $view === 'normal' ? __('common.developer_view') : __('common.normal_view') }}</x-forms.button>
    </div>
    <div class="flex items-center gap-1 subtitle">{{ __('shared.use_variables_hint') }} <span
            class="dark:text-warning text-coollabs">@{{ environment.VARIABLENAME }}</span><x-helper
            helper="{{ __('shared.more_info_here') }}"></x-helper>
    </div>
    @if ($view === 'normal')
        <div class="flex flex-col gap-2">
            @forelse ($environment->environment_variables->sort()->sortBy('key') as $env)
                <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}"
                    :env="$env" type="environment" />
            @empty
                <div>{{ __('shared.no_environment_variables_found') }}</div>
            @endforelse
        </div>
    @else
        <form wire:submit='submit' class="flex flex-col gap-2">
            <x-forms.textarea canGate="update" :canResource="$environment" rows="20" class="whitespace-pre-wrap" id="variables" wire:model="variables"
                label="{{ __('shared.environment_shared_variables') }}"></x-forms.textarea>
            <x-forms.button canGate="update" :canResource="$environment" type="submit" class="btn btn-primary">{{ __('common.save_all_env_vars') }}</x-forms.button>
        </form>
    @endif
</div>
