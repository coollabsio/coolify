<div>
    <form wire:submit='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>{{ __('application.swarm_configuration') }}</h2>
            @can('update', $application)
                <x-forms.button type="submit">
                    {{ __('common.save') }}
                </x-forms.button>
            @else
                <x-forms.button type="submit" disabled
                    title="{{ __('application.no_permission_update_application') }}">
                    {{ __('common.save') }}
                </x-forms.button>
            @endcan
        </div>
        <div class="flex flex-col gap-2 py-4">
            <div class="flex flex-col items-end gap-2 xl:flex-row">
                <x-forms.input id="swarmReplicas" label="{{ __('application.replicas') }}" required canGate="update" :canResource="$application" />
                <x-forms.checkbox instantSave helper="{{ __('application.only_start_on_worker_nodes_helper') }}"
                    id="isSwarmOnlyWorkerNodes" label="{{ __('application.only_start_on_worker_nodes') }}" canGate="update" :canResource="$application" />
            </div>
            <x-forms.textarea id="swarmPlacementConstraints" rows="7" label="{{ __('application.custom_placement_constraints') }}"
                placeholder="{{ __('application.custom_placement_constraints_placeholder') }}" canGate="update" :canResource="$application" />
        </div>
    </form>

</div>
