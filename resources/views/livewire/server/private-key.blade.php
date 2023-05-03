<div>
    @forelse ($private_keys as $private_key)
        <x-inputs.button wire:click='setPrivateKey({{ $private_key->id }})'>{{ $private_key->name }}</x-inputs.button>
    @empty
        <p>No private keys found</p>
    @endforelse
</div>
