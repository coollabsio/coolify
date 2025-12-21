<div>
    <h1>Create a new Application</h1>
    <div class="pb-4">{{ __('application.deploy_deploy_key_desc') }}</div>
    <div class="flex flex-col ">
        @if ($current_step === 'private_keys')
            <h2 class="pb-4">Select a private key</h2>
            <div class="flex flex-col justify-center gap-2 text-left ">
                @forelse ($private_keys as $key)
                    @if ($private_key_id == $key->id)
                        <div class="gap-2 py-4 cursor-pointer group coolbox"
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
                        <div class="gap-2 py-4 cursor-pointer group coolbox"
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
                            {{ __('server.no_private_keys_found') }}
                        </div>
                        <a href="{{ route('security.private-key.index') }}" {{ wireNavigate() }}>
                            <x-forms.button>{{ __('application.create_new_private_key') }}</x-forms.button>
                        </a>
                    </div>
                @endforelse
            </div>
        @endif
        @if ($current_step === 'repository')
            <form class="flex flex-col gap-2" wire:submit='submit'>
                <x-forms.input id="repository_url" required label="{{ __('application.repository_url') }}" />
                <div class="flex gap-2">
                    <x-forms.input id="branch" required label="{{ __('application.branch') }}" />
                    <x-forms.select wire:model.live="build_pack" label="{{ __('application.build_pack') }}" required>
                        <option value="nixpacks">Nixpacks</option>
                        <option value="static">Static</option>
                        <option value="dockerfile">Dockerfile</option>
                        <option value="dockercompose">{{ __('application.docker_compose') }}</option>
                    </x-forms.select>
                    @if ($is_static)
                        <x-forms.input id="publish_directory" required label="{{ __('application.publish_directory') }}" />
                    @endif
                </div>
                @if ($build_pack === 'dockercompose')
                    <div x-data="{
                        baseDir: '{{ $base_directory }}',
                        composeLocation: '{{ $docker_compose_location }}',
                        normalizePath(path) {
                            if (!path || path.trim() === '') return '/';
                            path = path.trim();
                            // Remove trailing slashes
                            path = path.replace(/\/+$/, '');
                            // Ensure leading slash
                            if (!path.startsWith('/')) {
                                path = '/' + path;
                            }
                            return path;
                        },
                        normalizeBaseDir() {
                            this.baseDir = this.normalizePath(this.baseDir);
                        },
                        normalizeComposeLocation() {
                            this.composeLocation = this.normalizePath(this.composeLocation);
                        }
                    }" class="gap-2 flex flex-col">
                        <x-forms.input placeholder="/" wire:model.defer="base_directory" label="{{ __('application.base_directory') }}"
                            helper="{{ __('application.base_directory_helper') }}" x-model="baseDir"
                            @blur="normalizeBaseDir()" />
                        <x-forms.input placeholder="/docker-compose.yaml" wire:model.defer="docker_compose_location"
                            label="{{ __('application.docker_compose_location') }}" helper="{{ __('application.docker_compose_location_helper') }}"
                            x-model="composeLocation" @blur="normalizeComposeLocation()" />
                        <div class="pt-2">
                            <span>
                                {{ __('application.compose_file_location_in_repository') }} </span><span class='dark:text-warning'
                                x-text='(baseDir === "/" ? "" : baseDir) + (composeLocation.startsWith("/") ? composeLocation : "/" + composeLocation)'></span>
                        </div>
                    </div>
                @else
                    <x-forms.input wire:model="base_directory" label="{{ __('application.base_directory') }}"
                        helper="{{ __('application.base_directory_helper') }}" />
                @endif
                @if ($show_is_static)
                    <x-forms.input type="number" required id="port" label="{{ __('application.port') }}" :readonly="$is_static || $build_pack === 'static'" />
                    <div class="w-52">
                        <x-forms.checkbox instantSave id="is_static" label="{{ __('application.is_static_site') }}"
                            helper="{{ __('application.is_static_site_helper') }}" />
                    </div>
                @endif
                <x-forms.button type="submit" class="mt-4">
                    {{ __('common.continue') }}
                </x-forms.button>
            </form>
        @endif
    </div>
</div>
