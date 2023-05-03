<x-layout>
    <h1> {{ $server->name }}</h1>

    <livewire:server.proxy :server="$server"/>
</x-layout>
