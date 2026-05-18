<div>
    <h2>Destination</h2>
    <div class="">The destination server / network where your application will be deployed to.</div>
    <div class="py-4 ">
        <p>Server: {{ data_get($destination, 'server.name') }}</p>
        @if ($destination->getMorphClass() === 'App\Models\KubernetesCluster')
            <p>Namespace: {{ $destination->namespace }}</p>
            <p>Ingress Class: {{ $destination->ingress_class }}</p>
        @else
            <p>Destination Network: {{ $destination->network }}</p>
        @endif
    </div>
</div>
