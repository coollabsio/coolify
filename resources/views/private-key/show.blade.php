<x-layout>
    <h1>Private Key</h1>
    <div class="pt-2 pb-10 text-sm">Sssh, it is private</div>
    <livewire:private-key.change :private_key_uuid="$private_key->uuid" />
</x-layout>
