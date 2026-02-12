<div>
    <form wire:submit='submit' class="flex flex-col">
        <div class="form-card max-w-none">
            <div class="form-section-title">
                <h2>Swarm Configuration</h2>
                <div class="flex items-center gap-2">
                    @can('update', $application)
                        <x-forms.button type="submit">
                            Save
                        </x-forms.button>
                    @else
                        <x-forms.button type="submit" disabled
                            title="You don't have permission to update this application. Contact your team administrator for access.">
                            Save
                        </x-forms.button>
                    @endcan
                </div>
            </div>
        <div class="flex flex-col gap-10">
            <div class="flex flex-col items-end gap-8 xl:flex-row">
                <x-forms.input id="swarmReplicas" label="Replicas" required canGate="update" :canResource="$application" />
                <x-forms.checkbox instantSave helper="If turned off, this resource will start on manager nodes too."
                    id="isSwarmOnlyWorkerNodes" label="Only Start on Worker nodes" canGate="update" :canResource="$application" />
            </div>
            <x-forms.textarea id="swarmPlacementConstraints" rows="7" label="Custom Placement Constraints"
                placeholder="placement:
    constraints:
        - 'node.role == worker'" canGate="update" :canResource="$application" />
        </div>
        </div>
    </form>

</div>
