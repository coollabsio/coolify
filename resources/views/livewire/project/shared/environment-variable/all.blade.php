<div class="flex flex-col gap-4">
    <div>
        <div class="flex items-center gap-2">
            <h2>Environment Variables</h2>
            @if ($resource->type() !== 'service')
                <x-modal-input buttonTitle="+ Add" title="New Environment Variable">
                    <livewire:project.shared.environment-variable.add />
                </x-modal-input>
            @endif
            <x-forms.button
                wire:click='switch'>{{ $view === 'normal' ? 'Developer view' : 'Normal view' }}</x-forms.button>
        </div>
        <div>Environment variables (secrets) for this resource.</div>
        @if ($this->resourceClass === 'App\Models\Application' && data_get($this->resource, 'build_pack') !== 'dockercompose')
            <div class="w-64 pt-2">
                <x-forms.checkbox id="resource.settings.is_env_sorting_enabled" label="Sort alphabetically"
                    helper="Turn this off if one environment is dependent on an other. It will be sorted by creation order." instantSave></x-forms.checkbox>
            </div>
        @endif
        @if ($resource->type() === 'service' || $resource?->build_pack === 'dockercompose')
            <div class="pt-4 dark:text-warning text-coollabs">Hardcoded variables are not shown here.</div>
        @endif
    </div>
    @if ($view === 'normal')
        @forelse ($resource->environment_variables as $env)
            <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}"
                :env="$env" :type="$resource->type()" />
        @empty
            <div>No environment variables found.</div>
        @endforelse
        @if ($resource->type() === 'application' && $resource->environment_variables_preview->count() > 0 && $showPreview)
            <div>
                <h3>Preview Deployments</h3>
                <div>Environment (secrets) variables for Preview Deployments.</div>
            </div>
            @foreach ($resource->environment_variables_preview as $env)
                <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}"
                    :env="$env" :type="$resource->type()" />
            @endforeach
        @endif
    @else
        <form wire:submit='saveVariables(false)' class="flex flex-col gap-2">
            <x-forms.textarea rows="10" class="whitespace-pre-wrap" id="variables"></x-forms.textarea>
            <x-forms.button type="submit" class="btn btn-primary">Save</x-forms.button>
        </form>
        @if ($showPreview)
            <form wire:submit='saveVariables(true)' class="flex flex-col gap-2">
                <x-forms.textarea rows="10" class="whitespace-pre-wrap" label="Preview Environment Variables"
                    id="variablesPreview"></x-forms.textarea>
                <x-forms.button type="submit" class="btn btn-primary">Save</x-forms.button>
            </form>
        @endif
    @endif
</div>
