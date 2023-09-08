<div class="flex flex-col gap-2">
    <div>
        <div class="flex items-center gap-2">
            <h2>Environment Variables</h2>
            <x-forms.button class="btn" onclick="newVariable.showModal()">+ Add</x-forms.button>
            <livewire:project.shared.environment-variable.add />
            <x-forms.button
                wire:click='switch'>{{ $view === 'normal' ? 'Developer view' : 'Normal view' }}</x-forms.button>
        </div>
        <div>Environment variables (secrets) for this resource.</div>
    </div>
    @if ($view === 'normal')
        @forelse ($resource->environment_variables as $env)
            <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}"
                :env="$env" />
        @empty
            <div class="text-neutral-500">No environment variables found.</div>
        @endforelse
        @if ($resource->type() === 'application' && $resource->environment_variables_preview->count() > 0 && $showPreview)
            <div>
                <h3>Preview Deployments</h3>
                <div>Environment (secrets) variables for Preview Deployments.</div>
            </div>
            @foreach ($resource->environment_variables_preview as $env)
                <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}"
                    :env="$env" />
            @endforeach
        @endif
    @else
        <form wire:submit.prevent='saveVariables(false)' class="flex flex-col gap-2">
            <x-forms.textarea rows=5 class="whitespace-pre-wrap" label="Environment Variables"
                id="variables"></x-forms.textarea>
            <x-forms.button type="submit" class="btn btn-primary">Save</x-forms.button>
        </form>
        @if ($showPreview)
            <form wire:submit.prevent='saveVariables(true)' class="flex flex-col gap-2">
                <x-forms.textarea rows=5 class="whitespace-pre-wrap" label="Preview Environment Variables"
                    id="variablesPreview"></x-forms.textarea>
                <x-forms.button type="submit" class="btn btn-primary">Save</x-forms.button>
            </form>
        @endif
    @endif
</div>
