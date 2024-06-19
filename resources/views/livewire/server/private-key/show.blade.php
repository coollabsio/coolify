<div>
    <x-slot:title>
        Server Connection | Coolify
    </x-slot>
    <x-server.navbar :server="$server" :parameters="$parameters" />
    <livewire:server.show-private-key :server="$server" :privateKeys="$privateKeys" />
</div>
