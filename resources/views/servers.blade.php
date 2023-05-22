<x-layout>
    <h1>Servers</h1>
    @forelse ($servers as $server)
        <a href="{{ route('server.show', ['server_uuid' => data_get($server, 'uuid')]) }}"
            class="box">{{ $server->name }}</a>
    @empty
        <div class="flex flex-col items-center justify-center h-full pt-32">
            <div class="">Without a server, you won't be able to do much...</div>
            <div>Let's create <a class="underline text-warning" href="{{ route('server.new') }}">your
                    first</a> one!</div>
        </div>
    @endforelse
</x-layout>
