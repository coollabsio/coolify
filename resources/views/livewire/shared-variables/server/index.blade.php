<div>
    <x-slot:title>
        Server Variables | Coolify
    </x-slot>
    <div class="flex gap-2">
        <h1>Servers</h1>
    </div>
    <div class="subtitle">List of your servers.</div>
    <div class="flex flex-col gap-2">
        @forelse ($servers as $server)
            <a class="coolbox group"
                href="{{ route('shared-variables.server.show', ['server_uuid' => data_get($server, 'uuid')]) }}" {{ wireNavigate() }}>
                <div class="flex flex-col justify-center mx-6 ">
                    <div class="box-title">{{ $server->name }}</div>
                    <div class="box-description ">
                        {{ $server->description }}</div>
                </div>
            </a>
        @empty
            <div>
                <div>No server found.</div>
            </div>
        @endforelse
    </div>
</div>
