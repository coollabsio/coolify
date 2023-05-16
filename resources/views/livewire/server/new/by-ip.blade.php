<div>
    <form class="flex flex-col gap-1" wire:submit.prevent='submit'>
        <h1>New Server</h1>
        <x-inputs.input id="name" label="Name" required />
        <x-inputs.input id="description" label="Description" />
        <x-inputs.input id="ip" label="IP Address" required />
        <x-inputs.input id="user" label="User" />
        <x-inputs.input type="number" id="port" label="Port" />
        <label>Private Key</label>
        <select wire:model.defer="private_key_id">
            <option disabled>Select a private key</option>
            @foreach ($private_keys as $key)
                @if ($loop->first)
                    <option selected value="{{ $key->id }}">{{ $key->name }}</option>
                @else
                    <option value="{{ $key->id }}">{{ $key->name }}</option>
                @endif
            @endforeach
        </select>
        <x-inputs.button isBold type="submit">
            Save
        </x-inputs.button>
    </form>
</div>
