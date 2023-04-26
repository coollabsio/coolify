<div>
    <form wire:submit.prevent='submit'>
        <x-form-input id="ip" label="IP Address" required />
        <x-form-input id="user" label="User" />
        <x-form-input type="number" id="port" label="Port" />
        <button type="submit">
            Submit
        </button>
    </form>
    <div>Select a private key:</div>
    @foreach ($private_keys as $key)
        <button @if ($private_key_id == $key->id) class="bg-green-500" @endif
            wire:click="setPrivateKey('{{ $key->id }}')">{{ $key->name }}</button>
    @endforeach
    <div> Add a new One:</div>
    <form wire:submit.prevent='addPrivateKey'>
        <x-form-input id="new_private_key_name" label="Name" required />
        <x-form-input id="new_private_key_description" label="Longer Description" />
        <x-form-input type="textarea" id="new_private_key_value" label="Private Key" required />
        <button type="submit">
            Submit
        </button>
    </form>
</div>
