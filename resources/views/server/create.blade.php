<x-layout>
    @if ($private_keys->count() === 0)
        <h1>Create Private Key</h1>
        <div class="subtitle">You need to create a private key before you can create a server.</div>
        <livewire:private-key.create from="server" />
    @else
        <livewire:server.new.by-ip :private_keys="$private_keys" :limit_reached="$limit_reached" />
    @endif
</x-layout>
