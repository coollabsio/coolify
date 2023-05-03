<div>
    <form class="flex flex-col" wire:submit.prevent='submit'>
        <x-form-input id="name" label="Name" required />
        <x-form-input id="description" label="Description" required />
        <x-form-input id="ip" label="IP Address" required />
        <x-form-input id="user" label="User" />
        <x-form-input type="number" id="port" label="Port" />
        <x-form-input id="private_key_id" label="Private Key" required hidden />
        <button class="mt-4" type="submit">
            Submit
        </button>
    </form>
    <div class="flex gap-4">
        <div>
            <h1>Select a private key</h1>
            @foreach ($private_keys as $key)
                <button @if ($private_key_id == $key->id) class="bg-green-500" @endif
                    wire:click.defer="setPrivateKey('{{ $key->id }}')">{{ $key->name }}</button>
            @endforeach
        </div>
        <div>
            <h2>Add a new One</h2>
            <form class="flex flex-col gap-2" wire:submit.prevent='addPrivateKey'>
                <x-form-input id="new_private_key_name" label="Name" required />
                <x-form-input id="new_private_key_description" label="Longer Description" />
                <x-form-input type="textarea" id="new_private_key_value" label="Private Key" required />
                <button type="submit">
                    Submit
                </button>
            </form>
        </div>
    </div>
</div>
