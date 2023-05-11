<div>
    <div>
        <h1>Select a private key</h1>
        @foreach ($private_keys as $key)
            @if ($private_key_id == $key->id)
                <x-inputs.button class="bg-blue-500" wire:click.defer="setPrivateKey('{{ $key->id }}')">
                    {{ $key->name }}</x-inputs.button>
            @else
                <x-inputs.button wire:click.defer="setPrivateKey('{{ $key->id }}')">{{ $key->name }}
                </x-inputs.button>
            @endif
        @endforeach
    </div>
    @isset($private_key_id)
        <h1>Choose a repository</h1>
        <form wire:submit.prevent='submit'>
            <div class="flex items-end gap-2 pb-2">
                <x-inputs.input class="w-96" id="repository_url" label="Repository URL" />
                @if ($is_static)
                    <x-inputs.input id="publish_directory" label="Publish Directory" />
                @else
                    <x-inputs.input type="number" id="port" label="Port" :readonly="$is_static" />
                @endif
                <x-inputs.input instantSave type="checkbox" id="is_static" label="Static Site?" />
            </div>
            <x-inputs.button type="submit">
                Submit
            </x-inputs.button>
        </form>
    @endisset
</div>
