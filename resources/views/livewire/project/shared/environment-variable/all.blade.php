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
        <div>
            <h3>Production Environment Variables</h3>
            <div>Environment (secrets) variables for Production.</div>
        </div>
        @forelse ($this->environmentVariables as $env)
            <livewire:project.shared.environment-variable.show wire:key="environment-{{ $env->id }}" :env="$env"
                :type="$resource->type()" />
        @empty
            <div>No environment variables found.</div>
        @endforelse
        @if (($resource->type() === 'service' || $resource?->build_pack === 'dockercompose') && $this->hardcodedEnvironmentVariables->isNotEmpty())
            @foreach ($this->hardcodedEnvironmentVariables as $index => $env)
                <livewire:project.shared.environment-variable.show-hardcoded
                    wire:key="hardcoded-prod-{{ $env['key'] }}-{{ $env['service_name'] ?? 'default' }}-{{ $index }}"
                    :env="$env" />
            @endforeach
        @endif
        @if ($resource->type() === 'application' && $resource->environment_variables_preview->count() > 0 && $showPreview)
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
    @else
        <form wire:submit.prevent='submit' class="flex flex-col gap-2">
            @can('manageEnvironment', $resource)
                <x-callout type="info" title="Note" class="mb-2">
                    Inline comments with space before # (e.g., <code class="font-mono">KEY=value #comment</code>) are stripped.
                </x-callout>

                <x-forms.textarea rows="10" class="whitespace-pre-wrap" id="variables" wire:model="variables"
                    label="Production Environment Variables"></x-forms.textarea>

                @if ($showPreview)
                    <x-forms.textarea rows="10" class="whitespace-pre-wrap" label="Preview Deployments Environment Variables"
                        id="variablesPreview" wire:model="variablesPreview"></x-forms.textarea>
                @endif

                <x-forms.button type="submit" class="btn btn-primary">Save All Environment Variables</x-forms.button>
            @else
                <x-forms.textarea rows="10" class="whitespace-pre-wrap" id="variables" wire:model="variables"
                    label="Production Environment Variables" disabled></x-forms.textarea>

                @if ($showPreview)
                    <x-forms.textarea rows="10" class="whitespace-pre-wrap" label="Preview Deployments Environment Variables"
                        id="variablesPreview" wire:model="variablesPreview" disabled></x-forms.textarea>
                @endif
            @endcan
        </form>
    @endif
</div>