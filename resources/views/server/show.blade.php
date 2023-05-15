<x-layout>
    <livewire:server.form :server_id="$server->id" />
    <div class="flex items-center gap-2">
        <h2>Private Key</h2>
        <a href="{{ route('server.private-key', ['server_uuid' => $server->uuid]) }}">
            <x-inputs.button isBold>Change</x-inputs.button>
        </a>
    </div>
    <p>{{ $server->privateKey->name }}</p>
    <div class="flex items-center gap-2">
        <h2>Destinations</h2>
        <a href="{{ route('destination.new', ['server_id' => $server->id]) }}">
            <x-inputs.button isBold>New</x-inputs.button>
        </a>
    </div>
    @if ($server->standaloneDockers->count() > 0)
        @foreach ($server->standaloneDockers as $docker)
            <p>Network: {{ data_get($docker, 'network') }}</p>
        @endforeach
    @else
        <p>No destinations found</p>
    @endif
    <div class="flex items-center gap-2">
        <h2>Proxy</h2>
        @if ($server->settings->is_validated)
            <div>{{ $server->extra_attributes->proxy_status }}</div>
        @endif
    </div>
    @if ($server->settings->is_validated)
        <livewire:server.proxy :server="$server" />
    @else
        <p>Server is not validated. Validate first.</p>
    @endif
</x-layout>
