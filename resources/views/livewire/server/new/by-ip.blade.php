<div class="w-full">
    @if ($limit_reached)
        <x-limit-reached name="servers" />
    @else
        <form class="flex flex-col w-full gap-2" wire:submit='submit'>
            <div class="flex w-full gap-2 flex-wrap sm:flex-nowrap">
                <x-forms.input id="name" label="Name" required />
                <x-forms.input id="description" label="Description" />
            </div>
            <div class="flex gap-2 flex-wrap sm:flex-nowrap">
                <x-forms.input id="ip" label="IP Address/Domain" required
                    helper="An IP Address (127.0.0.1) or domain (example.com)." />
                <x-forms.input type="number" id="port" label="Port" required />
            </div>
            <x-forms.input id="user" label="User" required />
            <div class="text-xs dark:text-warning text-coollabs ">Non-root user is experimental: <a
                    class="font-bold underline" target="_blank"
                    href="https://coolify.io/docs/knowledge-base/server/non-root-user">docs</a>.</div>
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
            <div class="">
                <x-forms.checkbox instantSave type="checkbox" id="is_build_server" label="Use it as a build server?" />
            </div>
            <div class="">
                <h3 class="pt-6">Swarm <span class="text-xs text-neutral-500">(experimental)</span></h3>
                <div class="pb-4">Read the docs <a class='underline dark:text-white'
                        href='https://coolify.io/docs/knowledge-base/docker/swarm' target='_blank'>here</a>.</div>
                @if ($is_swarm_worker || $is_build_server)
                    <x-forms.checkbox disabled instantSave type="checkbox" id="is_swarm_manager"
                        helper="For more information, please read the documentation <a class='dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/swarm' target='_blank'>here</a>."
                        label="Is it a Swarm Manager?" />
                @else
                    <x-forms.checkbox type="checkbox" instantSave id="is_swarm_manager"
                        helper="For more information, please read the documentation <a class='dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/swarm' target='_blank'>here</a>."
                        label="Is it a Swarm Manager?" />
                @endif
                @if ($is_swarm_manager || $is_build_server)
                    <x-forms.checkbox disabled instantSave type="checkbox" id="is_swarm_worker"
                        helper="For more information, please read the documentation <a class='dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/swarm' target='_blank'>here</a>."
                        label="Is it a Swarm Worker?" />
                @else
                    <x-forms.checkbox type="checkbox" instantSave id="is_swarm_worker"
                        helper="For more information, please read the documentation <a class='dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/swarm' target='_blank'>here</a>."
                        label="Is it a Swarm Worker?" />
                @endif
                @if ($is_swarm_worker && count($swarm_managers) > 0)
                    <div class="py-4">
                        <x-forms.select label="Select a Swarm Cluster" id="selected_swarm_cluster" required>
                            @foreach ($swarm_managers as $server)
                                @if ($loop->first)
                                    <option selected value="{{ $server->id }}">{{ $server->name }}</option>
                                @else
                                    <option value="{{ $server->id }}">{{ $server->name }}</option>
                                @endif
                            @endforeach
                        </x-forms.select>
                    </div>
                @endif
            </div>
            <x-forms.button type="submit">
                Continue
            </x-forms.button>
        </form>
    @endif
</div>
