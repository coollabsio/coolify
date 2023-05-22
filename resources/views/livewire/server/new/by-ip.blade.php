<div>
    <form class="flex flex-col gap-1" wire:submit.prevent='submit'>
        <div class="flex items-center gap-2">
            <h1>New Server</h1>
            <x-inputs.button type="submit">
                Save
            </x-inputs.button>
        </div>
        <x-inputs.input id="name" label="Name" required />
        <x-inputs.input id="description" label="Description" />
        <x-inputs.input id="ip" label="IP Address" required
            helper="Could be IP Address (127.0.0.1) or Domain Name (duckduckgo.com)." />
        <x-inputs.input id="user" label="User" required />
        <x-inputs.input type="number" id="port" label="Port" required />
        <label>Private Key</label>
        <x-inputs.select wire:model.defer="private_key_id">
            <option disabled>Select a private key</option>
            @foreach ($private_keys as $key)
                @if ($loop->first)
                    <option selected value="{{ $key->id }}">{{ $key->name }}</option>
                @else
                    <option value="{{ $key->id }}">{{ $key->name }}</option>
                @endif
            @endforeach
        </x-inputs.select>
        <x-inputs.checkbox instantSave noDirty id="is_part_of_swarm" label="Is it part of a Swarm cluster?" />

    </form>
</div>
