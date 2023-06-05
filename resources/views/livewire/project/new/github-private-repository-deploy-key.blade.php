<div>
    <h1>New Application</h1>
    <div class="pb-4 text-sm">Deploy any public or private git repositories through a Deploy Key.</div>
    <h3 class="py-2">Select a private key</h3>
    @foreach ($private_keys as $key)
        @if ($private_key_id == $key->id)
            <x-forms.button class="bg-coollabs hover:bg-coollabs-100"
                wire:click.defer="setPrivateKey('{{ $key->id }}')">
                {{ $key->name }}</x-forms.button>
        @else
            <x-forms.button wire:click.defer="setPrivateKey('{{ $key->id }}')">{{ $key->name }}
            </x-forms.button>
        @endif
    @endforeach
    @isset($private_key_id)
        <form class="pt-6" wire:submit.prevent='submit'>
            <div class="flex items-end gap-2 pb-2">
                <x-forms.input class="w-96" id="repository_url" label="Repository URL" />
                @if ($is_static)
                    <x-forms.input id="publish_directory" label="Publish Directory" />
                @else
                    <x-forms.input type="number" id="port" label="Port" :readonly="$is_static" />
                @endif
                <x-forms.checkbox instantSave id="is_static" label="Static Site?" />
            </div>
            <x-forms.button type="submit">
                Add New Application
            </x-forms.button>
        </form>
    @endisset
</div>
