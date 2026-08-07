<form wire:submit="submit" class="application-settings-form flex flex-col gap-6">
    <x-unsaved-bar action="submit" />

    <x-application.settings-section title="Swarm configuration"
        description="Legacy placement and replica settings for Docker Swarm deployments.">
        <x-slot:actions>
            <x-deprecated-badge />
        </x-slot:actions>

        <x-callout type="warning" title="Deprecated">
            {{ config('deprecations.swarm') }}
        </x-callout>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <x-forms.input id="swarmReplicas" label="Replicas" required canGate="update"
                :canResource="$application" />
            <x-forms.listbox id="isSwarmOnlyWorkerNodes" label="Node placement" live onChange="instantSave"
                :disabled="! auth()->user()->can('update', $application)" :options="[
                    ['value' => true, 'label' => 'Worker nodes only'],
                    ['value' => false, 'label' => 'Manager and worker nodes'],
                ]" />
            <div class="lg:col-span-2">
                <x-forms.textarea id="swarmPlacementConstraints" rows="8" label="Custom placement constraints"
                    placeholder="placement:
    constraints:
        - 'node.role == worker'" canGate="update" :canResource="$application" />
            </div>
        </div>
    </x-application.settings-section>
</form>
