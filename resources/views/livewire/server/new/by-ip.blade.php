<div>
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @else
        <h1>Create a new Server</h1>
        <div class="subtitle ">Servers are the main blocks of your infrastructure.</div>
        <form class="flex flex-col gap-2" wire:submit.prevent='submit'>
            <div class="flex gap-2">
                <x-forms.input id="name" label="Name" required />
                <x-forms.input id="description" label="Description" />
            </div>
            <div class="flex gap-2">
                <x-forms.input pattern="^(?!10\.(\d{1,3}\.){2}\d{1,3}|172\.(1[6-9]|2[0-9]|3[0-1])\.(\d{1,3}\.){1}\d{1,3}|192\.168\.(\d{1,3}\.){1}\d{1,3}|(?:[Ff][Cc00]|[Ff][Dd])[0-9a-fA-F]{2}:|fe80:).*$" id="ip" label="IP Address/Domain" required
                    helper="An IP Address (127.0.0.1) or domain (example.com)." />
                <x-forms.input id="user" label  ="User" required />
                <x-forms.input type="number" id="port" label="Port" required />
            </div>
            <x-forms.select label="Private Key" id="private_key_id">
                <option disabled>Select a private key</option>
                @foreach ($private_keys as $key)
                    @if ($loop->first)
                        <option selected value="{{ $key->id }}">{{ $key->name }}</option>
                    @else
                        <option value="{{ $key->id }}">{{ $key->name }}</option>
                    @endif
                @endforeach
            </x-forms.select>
            <x-forms.button type="submit">
                Save New Server
            </x-forms.button>
        </form>

    @endif
</div>
