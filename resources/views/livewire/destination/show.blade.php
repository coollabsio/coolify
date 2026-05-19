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
            <div class="grid grid-cols-1 gap-2 pt-4 md:grid-cols-3">
                <x-forms.input canGate="update" :canResource="$destination" id="namespace" label="Namespace" required />
                <x-forms.checkbox canGate="update" :canResource="$destination" id="createNamespace"
                    label="Create Namespace" />
                <x-forms.input canGate="update" :canResource="$destination" id="ingressClass" label="Ingress Class" required />
            </div>
            <div class="grid grid-cols-1 gap-2 pt-4 md:grid-cols-3">
                <x-forms.select canGate="update" :canResource="$destination" id="serviceType" label="Service Type" required>
                    <option value="ClusterIP">ClusterIP</option>
                    <option value="NodePort">NodePort</option>
                    <option value="LoadBalancer">LoadBalancer</option>
                </x-forms.select>
                <x-forms.input canGate="update" :canResource="$destination" id="ingressTlsSecret"
                    label="Ingress TLS Secret" />
                <x-forms.input canGate="update" :canResource="$destination" id="serviceAccountName"
                    label="Service Account" />
            </div>
            <div class="grid grid-cols-1 gap-2 pt-4 md:grid-cols-4">
                <x-forms.checkbox canGate="update" :canResource="$destination" id="createServiceAccount"
                    label="Create Service Account" />
                <x-forms.input canGate="update" :canResource="$destination" id="imagePullSecrets"
                    label="Image Pull Secrets" />
                <x-forms.input canGate="update" :canResource="$destination" id="storageClass" label="Storage Class" />
                <x-forms.input canGate="update" :canResource="$destination" id="storageSize" label="Storage Size"
                    required />
            </div>
            <div class="grid grid-cols-1 gap-2 pt-4 md:grid-cols-3">
                <x-forms.input canGate="update" :canResource="$destination" id="replicas" label="Replicas"
                    type="number" min="1" max="100" required />
                <x-forms.input canGate="update" :canResource="$destination" id="minReplicas" label="Min Replicas"
                    type="number" min="1" max="100" required />
                <x-forms.input canGate="update" :canResource="$destination" id="maxReplicas" label="Max Replicas"
                    type="number" min="1" max="100" required />
            </div>
            <div class="grid grid-cols-1 gap-2 pt-4 md:grid-cols-3">
                <x-forms.checkbox canGate="update" :canResource="$destination" id="autoscalingEnabled"
                    label="Autoscaling" />
                <x-forms.input canGate="update" :canResource="$destination" id="targetCpuUtilizationPercentage"
                    label="Target CPU %" type="number" min="1" max="100" required />
                <x-forms.checkbox canGate="update" :canResource="$destination" id="podDisruptionBudgetEnabled"
                    label="Pod Disruption Budget" />
            </div>
            <div class="grid grid-cols-1 gap-2 pt-4 md:grid-cols-3">
                <x-forms.input canGate="update" :canResource="$destination" id="podDisruptionBudgetMinAvailable"
                    label="PDB Min Available" />
                <x-forms.input canGate="update" :canResource="$destination" id="context" label="Context" />
                <x-forms.input canGate="update" :canResource="$destination" id="kubeconfigPath" label="Kubeconfig Path" />
            </div>
            <div class="grid grid-cols-1 gap-2 pt-4 md:grid-cols-2">
                <x-forms.textarea canGate="update" :canResource="$destination" id="ingressAnnotations"
                    label="Ingress Annotations" rows="4" spellcheck="false" />
                <x-forms.textarea canGate="update" :canResource="$destination" id="nodeSelector" label="Node Selector"
                    rows="4" spellcheck="false" />
            </div>
            <div class="pt-4">
                <x-forms.textarea canGate="update" :canResource="$destination" id="tolerations" label="Tolerations YAML"
                    rows="4" spellcheck="false" />
            </div>
            <div class="pt-4">
                <x-forms.textarea canGate="update" :canResource="$destination" id="kubeconfig" label="Kubeconfig"
                    rows="12" spellcheck="false" />
            </div>
            <div class="flex flex-col gap-3 pt-8">
                <div class="flex flex-wrap items-center gap-2">
                    <h2>Kubernetes Resources</h2>
                    <x-forms.button canGate="view" :canResource="$destination"
                        wire:click.prevent='refreshKubernetesResources'>
                        Refresh Resources
                    </x-forms.button>
                </div>
                <div wire:loading.delay wire:target="refreshKubernetesResources" class="text-sm text-neutral-500">
                    Loading Kubernetes resources...
                </div>
                <div class="grid grid-cols-1 gap-2 md:grid-cols-[minmax(0,1fr)_140px_auto_auto] md:items-end">
                    <x-forms.select canGate="view" :canResource="$destination" id="selectedKubernetesResource"
                        label="Workload">
                        <option value="">Select a workload</option>
                        @foreach ($kubernetesResources as $resource)
                            @if ($resource['scalable'])
                                <option value="{{ $resource['kind'] }}/{{ $resource['name'] }}">
                                    {{ $resource['kind'] }}/{{ $resource['name'] }}
                                </option>
                            @endif
                        @endforeach
                    </x-forms.select>
                    <x-forms.input canGate="update" :canResource="$destination" id="kubernetesResourceReplicas"
                        label="Replicas" type="number" min="0" max="100" />
                    <x-forms.button canGate="update" :canResource="$destination"
                        wire:click.prevent='scaleSelectedKubernetesResource'>
                        Scale
                    </x-forms.button>
                    <x-forms.button canGate="update" :canResource="$destination"
                        wire:click.prevent='restartSelectedKubernetesResource'>
                        Restart
                    </x-forms.button>
                </div>
                @if (count($kubernetesResources) > 0)
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-neutral-300 text-left dark:border-coolgray-300">
                                    <th class="p-2">Kind</th>
                                    <th class="p-2">Name</th>
                                    <th class="p-2">Status</th>
                                    <th class="p-2">Detail</th>
                                    <th class="p-2">Age</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($kubernetesResources as $resource)
                                    <tr wire:key="kubernetes-resource-{{ $resource['kind'] }}-{{ $resource['name'] }}"
                                        class="border-b border-neutral-200 dark:border-coolgray-300">
                                        <td class="p-2">{{ $resource['kind'] }}</td>
                                        <td class="p-2 font-mono text-xs">{{ $resource['name'] }}</td>
                                        <td class="p-2">{{ $resource['status'] }}</td>
                                        <td class="p-2">
                                            {{ $resource['detail'] }}
                                            @if ($resource['scalable'])
                                                <span class="text-neutral-500">
                                                    · replicas {{ $resource['ready_replicas'] }}/{{ $resource['desired_replicas'] }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="p-2">{{ $resource['age'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="text-sm text-neutral-500">No Coolify-managed resources loaded.</div>
                @endif
            </div>
            <div class="flex flex-col gap-3 pt-8">
                <div class="flex flex-wrap items-center gap-2">
                    <h2>Kubernetes Pods</h2>
                    <x-forms.button canGate="view" :canResource="$destination" wire:click.prevent='refreshKubernetesPods'>
                        Refresh Pods
                    </x-forms.button>
                    @if ($selectedKubernetesPod)
                        <x-forms.button canGate="view" :canResource="$destination"
                            wire:click.prevent='loadSelectedKubernetesPodLogs'>
                            Show Logs
                        </x-forms.button>
                        <x-modal-confirmation title="Restart selected Pod?" buttonTitle="Restart Pod" isErrorButton
                            submitAction="restartSelectedKubernetesPod" :actions="[
                                'This deletes the selected Pod. Its controller should create a replacement Pod automatically.',
                            ]" confirmationText="{{ $selectedKubernetesPod }}"
                            confirmationLabel="Please confirm the Pod name below" shortConfirmationLabel="Pod Name"
                            :confirmWithPassword="false" step2ButtonText="Restart Pod" canGate="update"
                            :canResource="$destination" />
                    @endif
                </div>
                <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                    <x-forms.select canGate="view" :canResource="$destination" id="selectedKubernetesPod"
                        label="Selected Pod">
                        <option value="">Select a Pod</option>
                        @foreach ($kubernetesPods as $pod)
                            <option value="{{ $pod['name'] }}">{{ $pod['name'] }}</option>
                        @endforeach
                    </x-forms.select>
                    <x-forms.select canGate="view" :canResource="$destination" id="selectedKubernetesContainer"
                        label="Container">
                        <option value="">Default</option>
                        @foreach ($this->selectedKubernetesPodContainers() as $container)
                            <option value="{{ $container }}">{{ $container }}</option>
                        @endforeach
                    </x-forms.select>
                </div>
                <div wire:loading.delay wire:target="refreshKubernetesPods,loadSelectedKubernetesPodLogs,restartSelectedKubernetesPod"
                    class="text-sm text-neutral-500">
                    Loading Kubernetes data...
                </div>
                @if (count($kubernetesPods) > 0)
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="border-b border-neutral-300 text-left dark:border-coolgray-300">
                                    <th class="p-2">Pod</th>
                                    <th class="p-2">Phase</th>
                                    <th class="p-2">Ready</th>
                                    <th class="p-2">Restarts</th>
                                    <th class="p-2">Node</th>
                                    <th class="p-2">Age</th>
                                    <th class="p-2">Containers</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($kubernetesPods as $pod)
                                    <tr wire:key="kubernetes-pod-{{ $pod['name'] }}"
                                        wire:click="$set('selectedKubernetesPod', '{{ $pod['name'] }}')"
                                        @class([
                                            'cursor-pointer border-b border-neutral-200 dark:border-coolgray-300',
                                            'bg-coolgray-100' => $selectedKubernetesPod === $pod['name'],
                                        ])>
                                        <td class="p-2 font-mono text-xs">{{ $pod['name'] }}</td>
                                        <td class="p-2">{{ $pod['phase'] }}</td>
                                        <td class="p-2">{{ $pod['ready'] }}</td>
                                        <td class="p-2">{{ $pod['restarts'] }}</td>
                                        <td class="p-2">{{ $pod['node'] }}</td>
                                        <td class="p-2">{{ $pod['age'] }}</td>
                                        <td class="p-2">{{ $pod['containers'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="text-sm text-neutral-500">No Coolify-managed Pods loaded.</div>
                @endif
                @if ($kubernetesPodLogs !== '')
                    <pre
                        class="max-h-96 overflow-auto whitespace-pre-wrap rounded bg-coolgray-100 p-3 text-xs dark:text-white">{{ $kubernetesPodLogs }}</pre>
                @endif
            </div>
        @endif
    </form>
</div>
