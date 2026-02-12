<div>
    <x-slot:title>
        Environment Variable | Coolify
    </x-slot>
    <div class="form-section-title mb-6">
        <h1>Shared Variables for {{ $project->name }}/{{ $environment->name }}</h1>
        <div class="flex items-center gap-2">
            @can('update', $environment)
                <x-modal-input buttonTitle="+ Add" title="New Shared Variable">
                    <livewire:project.shared.environment-variable.add :shared="true" />
                </x-modal-input>
            @endcan
            <x-forms.button canGate="update" :canResource="$environment" wire:click='switch'>{{ $view === 'normal' ? 'Developer view' : 'Normal view' }}</x-forms.button>
        </div>
    </div>
    <p class="flex items-center gap-1 text-sm text-neutral-500 dark:text-neutral-400 -mt-4 mb-4">You can use these variables anywhere with <span
            class="dark:text-warning text-coollabs">@{{ environment.VARIABLENAME }}</span><x-helper
            helper="More info <a class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/environment-variables#shared-variables' target='_blank'>here</a>."></x-helper>
    </p>
    @if ($view === 'normal')
        <div class="flex flex-col gap-10">
            @forelse ($environment->environment_variables->sort()->sortBy('key') as $env)
                <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}"
                    :env="$env" type="environment" />
            @empty
                <div class="empty-state">No environment variables found.</div>
            @endforelse
        </div>
    @else
        <form wire:submit='submit' class="flex flex-col gap-10">
            <x-forms.textarea canGate="update" :canResource="$environment" rows="20" class="whitespace-pre-wrap" id="variables" wire:model="variables"
                label="Environment Shared Variables"></x-forms.textarea>
            <x-forms.button canGate="update" :canResource="$environment" type="submit" class="btn btn-primary">Save All Environment Variables</x-forms.button>
        </form>
    @endif
</div>
