<div>
    <h2>Server</h2>
    <div class="">The destination server where your application will be deployed to.</div>
    <div class="py-4 ">
        <a class="box"
            href="{{ route('server.show', ['server_uuid' => data_get($destination, 'server.uuid')]) }}">On server <span class="px-1 text-warning">{{ data_get($destination, 'server.name') }}</span>
            in <span class="px-1 text-warning"> {{ data_get($destination, 'network') }} </span> network.</a>
    </div>
</div>
