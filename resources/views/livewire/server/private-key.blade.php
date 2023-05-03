<div>
    @forelse ($private_keys as $private_key)
        <button wire:click='setPrivateKey({{ $private_key->id }})'>{{ $private_key->name }}</button>
    @empty
        <p>No private keys found</p>
    @endforelse
</div>
