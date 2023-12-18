<div>
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @else
        <h1>Create a new Server</h1>
        <div class="subtitle">Servers are the main blocks of your infrastructure.</div>
        <form class="flex flex-col gap-2" wire:submit='submit'>
            <div class="flex gap-2">
                <x-forms.input id="name" label="Name" required />
                <x-forms.input id="description" label="Description" />
            </div>
            <div class="flex gap-2">
                <x-forms.input id="ip" label="IP Address/Domain" required
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
            <div class="w-72">
                @if ($is_swarm_worker)
                    <x-forms.checkbox disabled instantSave type="checkbox" id="is_swarm_manager"
                        helper="For more information, please read the documentation <a class='text-white' href='https://coolify.io/docs/swarm' target='_blank'>here</a>."
                        label="Is it a Swarm Manager?<span class='font-bold text-warning'>(alpha)</span>" />
                @else
                    <x-forms.checkbox instantSave type="checkbox" id="is_swarm_manager"
                        helper="For more information, please read the documentation <a class='text-white' href='https://coolify.io/docs/swarm' target='_blank'>here</a>."
                        label="Is it a Swarm Manager?<span class='font-bold text-warning'>(alpha)</span>" />
                @endif
                @if ($is_swarm_manager)
                    <x-forms.checkbox disabled instantSave type="checkbox" id="is_swarm_worker"
                        helper="For more information, please read the documentation <a class='text-white' href='https://coolify.io/docs/swarm' target='_blank'>here</a>."
                        label="Is it a Swarm Worker?<span class='font-bold text-warning'>(alpha)</span>" />
                @else
                    <x-forms.checkbox instantSave type="checkbox" id="is_swarm_worker"
                        helper="For more information, please read the documentation <a class='text-white' href='https://coolify.io/docs/swarm' target='_blank'>here</a>."
                        label="Is it a Swarm Worker?<span class='font-bold text-warning'>(alpha)</span>" />
                @endif
            </div>
            <x-forms.button type="submit">
                Save New Server
            </x-forms.button>
        </form>

    @endif
</div>
