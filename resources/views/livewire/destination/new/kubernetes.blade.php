@can('createAnyResource')
    <div class="w-full">
        <form class="flex flex-col gap-4" wire:submit='submit'>
            <div class="flex gap-2">
                <x-forms.input id="name" label="Name" required />
                <x-forms.input id="namespace" label="Namespace" required />
            </div>
            <div class="flex gap-2">
                <x-forms.input id="ingressClass" label="Ingress Class" required />
                <x-forms.select id="serviceType" label="Service Type" required>
                    <option value="ClusterIP">ClusterIP</option>
                    <option value="NodePort">NodePort</option>
                    <option value="LoadBalancer">LoadBalancer</option>
                </x-forms.select>
            </div>
            <div class="flex gap-2">
                <x-forms.input id="replicas" label="Replicas" type="number" min="1" max="100" required />
                <x-forms.input id="minReplicas" label="Min Replicas" type="number" min="1" max="100" required />
                <x-forms.input id="maxReplicas" label="Max Replicas" type="number" min="1" max="100" required />
            </div>
            <div class="flex gap-2">
                <x-forms.checkbox id="autoscalingEnabled" label="Autoscaling" />
                <x-forms.input id="targetCpuUtilizationPercentage" label="Target CPU %" type="number" min="1"
                    max="100" required />
            </div>
            <x-forms.input id="context" label="Context" />
            <x-forms.input id="kubeconfigPath" label="Kubeconfig Path" />
            <x-forms.textarea id="kubeconfig" label="Kubeconfig" rows="10" spellcheck="false" />
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
