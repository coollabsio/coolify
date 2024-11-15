<div>
    <h1>Create a new Application</h1>
    <div class="pb-4">Deploy any public or private Git repositories through a Deploy Key.</div>
    <div class="flex flex-col pt-4">
        @if ($current_step === 'private_keys')
            <h2 class="pb-4">Select a private key</h2>
            <div class="flex flex-col justify-center gap-2 text-left ">
                @forelse ($private_keys as $key)
                    @if ($private_key_id == $key->id)
                        <div class="gap-2 py-4 cursor-pointer group hover:bg-coollabs bg-coolgray-200 box"
                            wire:click="setPrivateKey('{{ $key->id }}')" wire:key="{{ $key->id }}">
                            <div class="flex flex-col mx-6">
                                <div class="box-title">
                                    {{ $key->name }}
                                </div>
                                <div class="box-description">
                                    {{ $key->description }}</div>
                                <span wire:target="loadRepositories" wire:loading.delay
                                    class="loading loading-xs dark:text-warning loading-spinner"></span>
                            </div>
                        </div>
                    @else
                        <div class="gap-2 py-4 cursor-pointer group hover:bg-coollabs bg-coolgray-200 box"
                            wire:click="setPrivateKey('{{ $key->id }}')" wire:key="{{ $key->id }}">
                            <div class="flex flex-col mx-6">
                                <div class="box-title">
                                    {{ $key->name }}
                                </div>
                                <div class="box-description">
                                    {{ $key->description }}</div>
                                <span wire:target="loadRepositories" wire:loading.delay
                                    class="loading loading-xs dark:text-warning loading-spinner"></span>
                            </div>
                        </div>
                    @endif
                @empty
                    <div class="flex flex-col items-center justify-center gap-2">
                        <div>
                            No private keys found.
                        </div>
                        <a href="{{ route('security.private-key.index') }}">
                            <x-forms.button>Create a new private key</x-forms.button>
                        </a>
                    </div>
                @endforelse
            </div>
        @endif
        @if ($current_step === 'repository')
            <h2 class="pb-4">Select a repository</h2>
            <form class="flex flex-col gap-2 pt-2" wire:submit='submit'>
                <x-forms.input id="repository_url" required label="Repository Url (https:// or git@)" />
                <div class="flex gap-2">
                    <x-forms.input id="branch" required label="Branch" />
                    <x-forms.select wire:model.live="build_pack" label="Build Pack" required>
                        <option value="nixpacks">Nixpacks</option>
                        <option value="static">Static</option>
                        <option value="dockerfile">Dockerfile</option>
                        <option value="dockercompose">Docker Compose</option>
                    </x-forms.select>
                    @if ($is_static)
                        <x-forms.input id="publish_directory" required label="Publish Directory" />
                    @endif
                </div>
                @if ($build_pack === 'dockercompose')
                    <x-forms.input placeholder="/" wire:model.blur="base_directory" label="Base Directory"
                        helper="Directory to use as root. Useful for monorepos." />
                    <x-forms.input placeholder="/docker-compose.yaml" id="docker_compose_location"
                        label="Docker Compose Location"
                        helper="It is calculated together with the Base Directory:<br><span class='dark:text-warning'>{{ Str::start($base_directory . $docker_compose_location, '/') }}</span>" />
                    Compose file location in your repository:<span
                        class='dark:text-warning'>{{ Str::start($base_directory . $docker_compose_location, '/') }}</span>
                @endif
                @if ($show_is_static)
                    <x-forms.input type="number" required id="port" label="Port" :readonly="$is_static || $build_pack === 'static'" />
                    <div class="w-52">
                        <x-forms.checkbox instantSave id="is_static" label="Is it a static site?"
                            helper="If your application is a static site or the final build assets should be served as a static site, enable this." />
                    </div>
                @endif
                <x-forms.button type="submit" class="mt-4">
                    Continue
                </x-forms.button>
            </form>
        @endif
    </div>
</div>
