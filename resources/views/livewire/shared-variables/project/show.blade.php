<div>
    <x-slot:title>
        Project Variable | Coolify
    </x-slot>
    <div class="flex gap-2 items-center">
        <h1>{{ __('shared.shared_variables_for') }} {{ data_get($project, 'name') }}</h1>
        @can('update', $project)
            <x-modal-input buttonTitle="{{ __('button.add') }}" title="{{ __('modal.new_shared_variable') }}">
                <livewire:project.shared.environment-variable.add :shared="true" />
            </x-modal-input>
        @endcan
        <x-forms.button canGate="update" :canResource="$project" wire:click='switch'>{{ $view === 'normal' ? __('common.developer_view') : __('common.normal_view') }}</x-forms.button>
    </div>
    <div class="flex flex-wrap gap-1 subtitle">
        <div>{{ __('shared.use_variables_hint') }}</div>
        <div class="dark:text-warning text-coollabs">@{{ project.VARIABLENAME }} </div>
        <x-helper
            helper="{{ __('shared.more_info_here') }}"></x-helper>
    </div>
    @if ($view === 'normal')
        <div class="flex flex-col gap-2">
            @forelse ($project->environment_variables->sort()->sortBy('key') as $env)
                <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}"
                    :env="$env" type="project" />
            @empty
                <div>{{ __('shared.no_env_vars_found') }}</div>
            @endforelse
        </div>
    @else
        <form wire:submit='submit' class="flex flex-col gap-2">
            <x-forms.textarea canGate="update" :canResource="$project" rows="20" class="whitespace-pre-wrap" id="variables" wire:model="variables"
                label="{{ __('shared.project_shared_variables') }}"></x-forms.textarea>
            <x-forms.button canGate="update" :canResource="$project" type="submit" class="btn btn-primary">{{ __('common.save_all_env_vars') }}</x-forms.button>
        </form>
    @endif
</div>
