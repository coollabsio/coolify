<x-layout>
    <x-server.navbar :server="$server" />
    <livewire:server.show-private-key :server="$server" :privateKeys="$privateKeys" />
</x-layout>
