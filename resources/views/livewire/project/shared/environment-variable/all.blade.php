<div class="flex flex-col gap-4">
    <div>
        <div class="flex items-center gap-2">
            <h2>Environment Variables</h2>
            <div class="flex flex-col items-center">
                <x-modal-input buttonTitle="+ Add" title="New Environment Variable">
                    <livewire:project.shared.environment-variable.add />
                </x-modal-input>
            </div>
            <x-forms.button
                wire:click='switch'>{{ $view === 'normal' ? 'Developer view' : 'Normal view (required to set variables at build time)' }}</x-forms.button>
        </div>
        <div>Environment variables (secrets) for this resource. </div>
        @if ($this->resourceClass === 'App\Models\Application' && data_get($this->resource, 'build_pack') !== 'dockercompose')
            <div class="w-64 pt-2">
                <x-forms.checkbox id="resource.settings.is_env_sorting_enabled" label="Sort alphabetically"
                    helper="Turn this off if one environment is dependent on an other. It will be sorted by creation order (like you pasted them or in the order you created them)."
                    instantSave></x-forms.checkbox>
            </div>
        @endif
        @if ($resource->type() === 'service' || $resource?->build_pack === 'dockercompose')
            <div class="flex items-center gap-1 pt-4 dark:text-warning text-coollabs">
                <svg class="hidden w-4 h-4 dark:text-warning lg:block" viewBox="0 0 256 256"
                    xmlns="http://www.w3.org/2000/svg">
                    <path fill="currentColor"
                        d="M240.26 186.1L152.81 34.23a28.74 28.74 0 0 0-49.62 0L15.74 186.1a27.45 27.45 0 0 0 0 27.71A28.31 28.31 0 0 0 40.55 228h174.9a28.31 28.31 0 0 0 24.79-14.19a27.45 27.45 0 0 0 .02-27.71m-20.8 15.7a4.46 4.46 0 0 1-4 2.2H40.55a4.46 4.46 0 0 1-4-2.2a3.56 3.56 0 0 1 0-3.73L124 46.2a4.77 4.77 0 0 1 8 0l87.44 151.87a3.56 3.56 0 0 1 .02 3.73M116 136v-32a12 12 0 0 1 24 0v32a12 12 0 0 1-24 0m28 40a16 16 0 1 1-16-16a16 16 0 0 1 16 16">
                    </path>
                </svg>
                Hardcoded variables are not shown here.
            </div>
            {{-- <div class="pb-4 dark:text-warning text-coollabs">If you would like to add a variable, you must add it to
                your compose file.</div> --}}
        @endif
    </div>
    @if ($view === 'normal')
        <div>
            <h3>Production Environment Variables</h3>
            <div>Environment (secrets) variables for Production.</div>
        </div>
        @php
            $requiredEmptyVars = $resource->environment_variables->filter(function($env) {
                return $env->is_required && empty($env->value);
            });
            $otherVars = $resource->environment_variables->diff($requiredEmptyVars);
        @endphp

        @forelse ($requiredEmptyVars->merge($otherVars) as $env)
            <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}"
                :env="$env" :type="$resource->type()" />
        @empty
            <div>No environment variables found.</div>
        @endforelse
        @if ($resource->type() === 'application' && $resource->environment_variables_preview->count() > 0 && $showPreview)
            <div>
                <h3>Preview Deployments Environment Variables</h3>
                <div>Environment (secrets) variables for Preview Deployments.</div>
            </div>
            @foreach ($resource->environment_variables_preview as $env)
                <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}"
                    :env="$env" :type="$resource->type()" />
            @endforeach
        @endif
    @else
        <form wire:submit.prevent='submit' class="flex flex-col gap-2">
            <x-forms.textarea rows="10" class="whitespace-pre-wrap" id="variables" wire:model="variables" label="Production Environment Variables"></x-forms.textarea>

            @if ($showPreview)
                <x-forms.textarea rows="10" class="whitespace-pre-wrap" label="Preview Deployments Environment Variables"
                    id="variablesPreview" wire:model="variablesPreview"></x-forms.textarea>
            @endif

            <x-forms.button type="submit" class="btn btn-primary">Save All Environment Variables</x-forms.button>
        </form>
    @endif
</div>
