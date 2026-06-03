<div class="flex flex-col gap-4">
    <div>
        <div class="flex items-center gap-2">
            <h2>Environment Variables</h2>
            @can('manageEnvironment', $resource)
                <div class="flex flex-col items-center">
                    <x-modal-input buttonTitle="+ Add" title="New Environment Variable" :closeOutside="false">
                        <livewire:project.shared.environment-variable.add />
                    </x-modal-input>
                </div>
                <x-forms.button
                    wire:click='switch'>{{ $view === 'normal' ? 'Developer view' : 'Normal view' }}</x-forms.button>
            @endcan
        </div>
        <div>Environment variables (secrets) for this resource. </div>
        @if ($resourceClass === 'App\Models\Application')
            <div class="flex flex-col gap-2 pt-2">
                @if (data_get($resource, 'build_pack') !== 'dockercompose')
                    <div class="w-64">
                        @can('manageEnvironment', $resource)
                            <x-forms.checkbox id="is_env_sorting_enabled" label="Sort alphabetically"
                                helper="Turn this off if one environment is dependent on another. It will be sorted by creation order (like you pasted them or in the order you created them)."
                                instantSave></x-forms.checkbox>
                        @else
                            <x-forms.checkbox id="is_env_sorting_enabled" label="Sort alphabetically"
                                helper="Turn this off if one environment is dependent on another. It will be sorted by creation order (like you pasted them or in the order you created them)."
                                disabled></x-forms.checkbox>
                        @endcan
                    </div>
                @endif
                <div class="w-64">
                    @can('manageEnvironment', $resource)
                        <x-forms.checkbox id="use_build_secrets" label="Use Docker Build Secrets"
                            helper="Enable Docker BuildKit secrets for enhanced security during builds. Secrets won't be exposed in the final image. Requires Docker 18.09+ with BuildKit support."
                            instantSave></x-forms.checkbox>
                    @else
                        <x-forms.checkbox id="use_build_secrets" label="Use Docker Build Secrets"
                            helper="Enable Docker BuildKit secrets for enhanced security during builds. Secrets won't be exposed in the final image. Requires Docker 18.09+ with BuildKit support."
                            disabled></x-forms.checkbox>
                    @endcan
                </div>
            </div>
        @endif
    </div>
    @if ($view === 'normal')
        <div class="w-full md:w-96">
            <div class="relative">
                <input type="search" placeholder="Search" aria-label="Search environment variables"
                    wire:model.live.debounce.300ms="search" class="w-full input pl-10" />
                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                    <div class="relative w-4 h-4">
                        <svg wire:loading.remove wire:target="search" aria-hidden="true"
                            class="absolute inset-0 w-4 h-4 dark:text-neutral-400" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </svg>
                        <svg wire:loading wire:target="search" aria-hidden="true"
                            class="absolute inset-0 w-4 h-4 text-coollabs dark:text-warning animate-spin" fill="none"
                            viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                stroke-width="4" />
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
                        </svg>
                    </div>
                </div>
            </div>
        </div>
        @if ($this->isSearchActive && ! $this->hasEnvironmentVariables)
            <div>No environment variables found.</div>
        @else
            @if ($this->environmentVariables->isNotEmpty() || $this->hardcodedEnvironmentVariables->isNotEmpty())
                <div>
                    <h3>Production Environment Variables</h3>
                    <div>Environment (secrets) variables for Production.</div>
                </div>
                @foreach ($this->environmentVariables as $env)
                    <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}" :env="$env"
                        :type="$resource->type()" />
                @endforeach
                @if (($resource->type() === 'service' || $resource?->build_pack === 'dockercompose') && $this->hardcodedEnvironmentVariables->isNotEmpty())
                    @foreach ($this->hardcodedEnvironmentVariables as $index => $env)
                        <livewire:project.shared.environment-variable.show-hardcoded
                            wire:key="hardcoded-prod-{{ $env['key'] }}-{{ $env['service_name'] ?? 'default' }}-{{ $index }}" :env="$env" />
                    @endforeach
                @endif
            @endif
            @if (
                $resource->type() === 'application' &&
                    $showPreview &&
                    ($this->environmentVariablesPreview->isNotEmpty() || $this->hardcodedEnvironmentVariablesPreview->isNotEmpty())
            )
                <div>
                    <h3>Preview Deployments Environment Variables</h3>
                    <div>Environment (secrets) variables for Preview Deployments.</div>
                </div>
                @foreach ($this->environmentVariablesPreview as $env)
                    <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}" :env="$env"
                        :type="$resource->type()" />
                @endforeach
                @if (($resource->type() === 'service' || $resource?->build_pack === 'dockercompose') && $this->hardcodedEnvironmentVariablesPreview->isNotEmpty())
                    @foreach ($this->hardcodedEnvironmentVariablesPreview as $index => $env)
                        <livewire:project.shared.environment-variable.show-hardcoded
                            wire:key="hardcoded-preview-{{ $env['key'] }}-{{ $env['service_name'] ?? 'default' }}-{{ $index }}"
                            :env="$env" />
                    @endforeach
                @endif
            @endif
        @endif
    @else
        <form wire:submit.prevent='submit' class="flex flex-col gap-2">
            @can('manageEnvironment', $resource)
                <x-callout type="info" title="Note" class="mb-2">
                    Inline comments with space before # (e.g., <code class="font-mono">KEY=value #comment</code>) are stripped.
                </x-callout>

                <x-forms.textarea rows="10" class="whitespace-pre-wrap font-sans" id="variables" wire:model="variables"
                    label="Production Environment Variables"></x-forms.textarea>

                @if ($showPreview)
                    <x-forms.textarea rows="10" class="whitespace-pre-wrap font-sans"
                        label="Preview Deployments Environment Variables" id="variablesPreview"
                        wire:model="variablesPreview"></x-forms.textarea>
                @endif

                <x-forms.button type="submit" class="btn btn-primary">Save All Environment Variables</x-forms.button>
            @else
                <x-forms.textarea rows="10" class="whitespace-pre-wrap font-sans" id="variables" wire:model="variables"
                    label="Production Environment Variables" disabled></x-forms.textarea>

                @if ($showPreview)
                    <x-forms.textarea rows="10" class="whitespace-pre-wrap font-sans"
                        label="Preview Deployments Environment Variables" id="variablesPreview" wire:model="variablesPreview"
                        disabled></x-forms.textarea>
                @endif
            @endcan
        </form>
    @endif
</div>
