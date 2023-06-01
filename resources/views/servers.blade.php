<x-layout>
    <h1 class="py-0">Servers</h1>
    <div class="pb-10 text-sm breadcrumbs">
        <ul>
            <li>
                All Servers
            </li>
        </ul>
    </div>
    @forelse ($servers as $server)
        <a href="{{ route('server.show', ['server_uuid' => data_get($server, 'uuid')]) }}"
            class="box">{{ $server->name }}</a>
    @empty
        <div class="flex flex-col">
            <div>Without a server, you won't be able to do much.</div>
            <div>Let's <a class="text-lg underline text-warning" href="{{ route('server.new') }}">create</a> your
                first one.</div>
        </div>
    @endforelse
</x-layout>
