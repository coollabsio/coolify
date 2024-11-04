<div>
    <form wire:submit='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>Swarm Configuration</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
        </div>
        <div class="flex flex-col gap-2 py-4">
            <div class="flex flex-col items-end gap-2 xl:flex-row">
                <x-forms.input id="swarmReplicas" label="Replicas" required />
                <x-forms.checkbox instantSave helper="If turned off, this resource will start on manager nodes too."
                    id="isSwarmOnlyWorkerNodes" label="Only Start on Worker nodes" />
            </div>
            <x-forms.textarea id="swarmPlacementConstraints" rows="7" label="Custom Placement Constraints"
                placeholder="placement:
    constraints:
        - 'node.role == worker'" />
        </div>
    </form>

</div>
