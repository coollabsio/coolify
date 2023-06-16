<div>
    <h1>Create a new Application</h1>
    <div class="pt-2 pb-10 ">Deploy any public or private GIT repositories through a Deploy Key.</div>
    <h3 class="py-2">Select a Private Key</h3>
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
    <a href="{{ route('private-key.new') }}">
        <x-forms.button isHighlighted>+</x-forms.button>
    </a>
    @isset($private_key_id)
        <form class="flex flex-col gap-2" wire:submit.prevent='submit'>
            <x-forms.input id="repository_url" label="Repository URL" helper="{!! __('repository.url') !!}" />
            <x-forms.input id="branch" label="Branch" />
            <x-forms.checkbox instantSave id="is_static" label="Static Site?" />
            @if ($is_static)
                <x-forms.input id="publish_directory" label="Publish Directory" />
            @else
                <x-forms.input type="number" id="port" label="Port" :readonly="$is_static" />
            @endif
            <x-forms.button type="submit">
                Save New Application
            </x-forms.button>
        </form>
    @endisset
</div>
