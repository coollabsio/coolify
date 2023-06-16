<x-layout>
    <h1>Servers</h1>
    <div class="pt-2 pb-10 ">All Servers</div>
    <div class="grid gap-2 lg:grid-cols-2">
        @forelse ($servers as $server)
            <a class="text-center hover:no-underline box group"
                href="{{ route('server.show', ['server_uuid' => data_get($server, 'uuid')]) }}">
                <div class="group-hover:text-white">
                    <div>{{ $server->name }}</div>
                    @if (!$server->settings->is_reachable)
                        <div class="text-xs text-error">not validated</div>
                    @endif
                </div>
            </a>
        @empty
            <div>
                <div>No servers found. Without a server, you won't be able to do much.</div>
                <x-use-magic-bar />
            </div>
        @endforelse
    </div>
</x-layout>
