<div>
    <h2 class="pb-0">Destination</h2>
    <div class="text-sm">The destination server / network where your application will be deployed to.</div>
    <div class="py-4">
        <p>Server: {{ data_get($destination, 'server.name') }}</p>
        <p>Destination: {{ $destination->network }}</p>
    </div>
</div>
