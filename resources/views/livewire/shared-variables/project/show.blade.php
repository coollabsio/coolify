<div>
    <x-slot:title>
        Project Variable | Coolify
    </x-slot>
    <div class="flex gap-2 items-center">
        <h1>Shared Variables for {{ data_get($project, 'name') }}</h1>
        @can('update', $project)
            <x-modal-input buttonTitle="+ Add" title="New Shared Variable">
                <livewire:project.shared.environment-variable.add :shared="true" />
            </x-modal-input>
        @endcan
        @can('update', $project)
            <x-forms.button wire:click='switch'>{{ $view === 'normal' ? 'Developer view' : 'Normal view' }}</x-forms.button>
        @endcan
    </div>
    <div class="flex flex-wrap gap-1 subtitle">
        <div>You can use these variables anywhere with</div>
        <div class="dark:text-warning text-coollabs">@{{ project.VARIABLENAME }} </div>
        <x-helper
            helper="More info <a class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/environment-variables#shared-variables' target='_blank'>here</a>."></x-helper>
    </div>
    @if ($view === 'normal')
        <div class="flex flex-col gap-2">
            @forelse ($project->environment_variables->sort()->sortBy('key') as $env)
                <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}"
                    :env="$env" type="project" />
            @empty
                <div>No environment variables found.</div>
            @endforelse
        </div>
    @else
        <form wire:submit='submit' class="flex flex-col gap-2">
            @can('update', $project)
                <x-forms.textarea rows="10" class="whitespace-pre-wrap" id="variables" wire:model="variables"
                    label="Project Shared Variables"></x-forms.textarea>
                <x-forms.button type="submit" class="btn btn-primary">Save All Environment Variables</x-forms.button>
            @else
                <x-forms.textarea rows="10" class="whitespace-pre-wrap" id="variables" wire:model="variables"
                    label="Project Shared Variables" disabled></x-forms.textarea>
            @endcan
        </form>
    @endif
</div>
