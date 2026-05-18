<div>
    <form class="flex flex-col">
        <div class="flex items-center gap-2">
            <h1>Destination</h1>
            <x-forms.button canGate="update" :canResource="$destination" wire:click.prevent='submit'
                type="submit">Save</x-forms.button>
            @if ($destination->getMorphClass() === 'App\Models\KubernetesCluster')
                <x-forms.button canGate="update" :canResource="$destination" wire:click.prevent='testConnection'>Test
                    Connection</x-forms.button>
            @endif
            @if ($network !== 'coolify')
                <x-modal-confirmation title="Confirm Destination Deletion?" buttonTitle="Delete Destination" isErrorButton
                    submitAction="delete" :actions="['This will delete the selected destination.']" confirmationText="{{ $destination->name }}"
                    confirmationLabel="Please confirm the execution of the actions by entering the Destination Name below"
                    shortConfirmationLabel="Destination Name" :confirmWithPassword="false" step2ButtonText="Permanently Delete" 
                    canGate="delete" :canResource="$destination" />
            @endif
        </div>

        @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
            <div class="subtitle ">A simple Docker network.</div>
        @elseif ($destination->getMorphClass() === 'App\Models\SwarmDocker')
            <div class="subtitle flex items-center gap-2">A swarm Docker network.
                <x-deprecated-badge />
            </div>
        @else
            <div class="subtitle">A Kubernetes cluster namespace.</div>
        @endif
        <div class="flex gap-2">
            <x-forms.input canGate="update" :canResource="$destination" id="name" label="Name" />
            <x-forms.input id="serverIp" label="Server IP" readonly />
            @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
                <x-forms.input id="network" label="Docker Network" readonly />
            @endif
        </div>
        @if ($destination->getMorphClass() === 'App\Models\KubernetesCluster')
            <div class="flex gap-2 pt-4">
                <x-forms.input canGate="update" :canResource="$destination" id="namespace" label="Namespace" required />
                <x-forms.input canGate="update" :canResource="$destination" id="ingressClass" label="Ingress Class" required />
                <x-forms.select canGate="update" :canResource="$destination" id="serviceType" label="Service Type" required>
                    <option value="ClusterIP">ClusterIP</option>
                    <option value="NodePort">NodePort</option>
                    <option value="LoadBalancer">LoadBalancer</option>
                </x-forms.select>
            </div>
            <div class="flex gap-2 pt-4">
                <x-forms.input canGate="update" :canResource="$destination" id="replicas" label="Replicas"
                    type="number" min="1" max="100" required />
                <x-forms.input canGate="update" :canResource="$destination" id="minReplicas" label="Min Replicas"
                    type="number" min="1" max="100" required />
                <x-forms.input canGate="update" :canResource="$destination" id="maxReplicas" label="Max Replicas"
                    type="number" min="1" max="100" required />
            </div>
            <div class="flex gap-2 pt-4">
                <x-forms.checkbox canGate="update" :canResource="$destination" id="autoscalingEnabled"
                    label="Autoscaling" />
                <x-forms.input canGate="update" :canResource="$destination" id="targetCpuUtilizationPercentage"
                    label="Target CPU %" type="number" min="1" max="100" required />
            </div>
            <div class="flex gap-2 pt-4">
                <x-forms.input canGate="update" :canResource="$destination" id="context" label="Context" />
                <x-forms.input canGate="update" :canResource="$destination" id="kubeconfigPath" label="Kubeconfig Path" />
            </div>
            <div class="pt-4">
                <x-forms.textarea canGate="update" :canResource="$destination" id="kubeconfig" label="Kubeconfig"
                    rows="12" spellcheck="false" />
            </div>
        @endif
    </form>
</div>
