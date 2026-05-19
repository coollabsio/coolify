@can('createAnyResource')
    <div class="w-full">
        <form class="flex flex-col gap-4" wire:submit='submit'>
            <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                <x-forms.input id="name" label="Name" required />
                <x-forms.input id="namespace" label="Namespace" required />
            </div>
            <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
                <x-forms.checkbox id="createNamespace" label="Create Namespace" />
                <x-forms.input id="ingressClass" label="Ingress Class" required />
                <x-forms.select id="serviceType" label="Service Type" required>
                    <option value="ClusterIP">ClusterIP</option>
                    <option value="NodePort">NodePort</option>
                    <option value="LoadBalancer">LoadBalancer</option>
                </x-forms.select>
            </div>
            <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
                <x-forms.input id="ingressTlsSecret" label="Ingress TLS Secret" />
                <x-forms.input id="serviceAccountName" label="Service Account" />
                <x-forms.checkbox id="createServiceAccount" label="Create Service Account" />
            </div>
            <div class="grid grid-cols-1 gap-2 md:grid-cols-4">
                <x-forms.input id="replicas" label="Replicas" type="number" min="1" max="100" required />
                <x-forms.input id="minReplicas" label="Min Replicas" type="number" min="1" max="100" required />
                <x-forms.input id="maxReplicas" label="Max Replicas" type="number" min="1" max="100" required />
                <x-forms.checkbox id="autoscalingEnabled" label="Autoscaling" />
            </div>
            <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
                <x-forms.input id="targetCpuUtilizationPercentage" label="Target CPU %" type="number" min="1"
                    max="100" required />
                <x-forms.checkbox id="podDisruptionBudgetEnabled" label="Pod Disruption Budget" />
                <x-forms.input id="podDisruptionBudgetMinAvailable" label="PDB Min Available" />
            </div>
            <div class="grid grid-cols-1 gap-2 md:grid-cols-3">
                <x-forms.input id="storageClass" label="Storage Class" />
                <x-forms.input id="storageSize" label="Storage Size" required />
                <x-forms.input id="imagePullSecrets" label="Image Pull Secrets" />
            </div>
            <x-forms.input id="context" label="Context" />
            <x-forms.input id="kubeconfigPath" label="Kubeconfig Path" />
            <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                <x-forms.textarea id="ingressAnnotations" label="Ingress Annotations" rows="4" spellcheck="false" />
                <x-forms.textarea id="nodeSelector" label="Node Selector" rows="4" spellcheck="false" />
            </div>
            <x-forms.textarea id="tolerations" label="Tolerations YAML" rows="4" spellcheck="false" />
            <x-forms.textarea id="kubeconfig" label="Kubeconfig" rows="8" spellcheck="false" />
            <x-forms.select id="serverId" label="Select a server" required wire:change="generateName">
                <option disabled>Select a server</option>
                @foreach ($servers as $server)
                    <option value="{{ $server->id }}">{{ $server->name }}</option>
                @endforeach
            </x-forms.select>
            <x-forms.button type="submit">
                Continue
            </x-forms.button>
        </form>
    </div>
@else
    <x-callout type="warning" title="Permission Required">
        You don't have permission to create new destinations. Please contact your team administrator for access.
    </x-callout>
@endcan
