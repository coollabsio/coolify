<div>
    <h2>Destination</h2>
    <div class="">The destination server / network where your application will be deployed to.</div>
    <div class="py-4 ">
        <p>Server: {{ data_get($destination, 'server.name') }}</p>
        <p>Destination Network: {{ $destination->network }}</p>
    </div>
</div>
