<div x-data="{ envModalOpen: false }">
    <h1>Create a new Application</h1>
    <div class="pb-4">Deploy any public or private Git repositories through a Deploy Key.</div>
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
                            No private keys found.
                        </div>
                        <a href="{{ route('security.private-key.index') }}" {{ wireNavigate() }}>
                            <x-forms.button>Create a new private key</x-forms.button>
                        </a>
                    </div>
                @endforelse
            </div>
        @endif
        @if ($current_step === 'repository')
            <form class="flex flex-col gap-2" wire:submit='submit'>
                <x-forms.input id="repository_url" required label="Repository URL (https:// or git@)" />
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

                {{-- Repository Detection --}}
                <div class="pt-6 mt-4 border-t border-neutral-200 dark:border-coolgray-300">
                    <h3 class="text-lg font-bold">Smart Scan</h3>
                    <p class="pt-1 pb-3 text-sm dark:text-neutral-400">Scan for Dockerfiles, Docker Compose files, and environment configuration.</p>

                    <div class="flex items-center gap-3">
                        <x-forms.button type="button" wire:click="detectRepository">
                            <span wire:loading.remove wire:target="detectRepository">Detect Repository</span>
                            <span wire:loading wire:target="detectRepository" class="inline-flex items-center gap-2">
                                <x-loading /> Scanning...
                            </span>
                        </x-forms.button>
                    </div>

                    @if ($detectionRan)
                        <div wire:loading.remove wire:target="detectRepository" class="pt-3">
                            <div class="flex items-center gap-3 flex-wrap text-sm">
                                @if (count($detectedDockerfiles))
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-sm dark:bg-coolgray-100 border border-neutral-200 dark:border-coolgray-300">
                                        <span class="badge badge-success"></span>
                                        Dockerfile{{ count($detectedDockerfiles) > 1 ? 's' : '' }}
                                        <span class="dark:text-neutral-400">({{ count($detectedDockerfiles) }})</span>
                                    </span>
                                @endif
                                @if (count($detectedDockerComposeFiles))
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-sm dark:bg-coolgray-100 border border-neutral-200 dark:border-coolgray-300">
                                        <span class="badge badge-success"></span>
                                        Docker Compose
                                        <span class="dark:text-neutral-400">({{ count($detectedDockerComposeFiles) }})</span>
                                    </span>
                                @endif
                                @include('livewire.project.new.partials.env-detection-badges')
                                @if (!count($detectedDockerfiles) && !count($detectedDockerComposeFiles) && !count($detectedEnvFiles))
                                    <span class="dark:text-neutral-400">No Dockerfile, Docker Compose, or env files detected.</span>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Configuration --}}
                <h3 class="pt-6 text-lg font-bold">Configuration</h3>

                {{-- Dockerfile selector when multiple detected --}}
                @if ($build_pack === 'dockerfile' && count($detectedDockerfiles) > 1)
                    <x-forms.select wire:model.live="selectedDockerfile" label="Dockerfile"
                        helper="Multiple Dockerfiles were detected in your repository. Select which one to use.">
                        @foreach ($detectedDockerfiles as $df)
                            <option value="{{ $df }}">{{ $df }}</option>
                        @endforeach
                    </x-forms.select>
                @endif

                @if ($build_pack === 'dockercompose')
                    <div x-data="{
                        baseDir: '{{ $base_directory }}',
                        composeLocation: '{{ $docker_compose_location }}',
                        normalizePath(path) {
                            if (!path || path.trim() === '') return '/';
                            path = path.trim();
                            path = path.replace(/\/+$/, '');
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
                        <x-forms.input placeholder="/" wire:model.defer="base_directory" label="Base Directory"
                            helper="Directory to use as root. Useful for monorepos." x-model="baseDir"
                            @blur="normalizeBaseDir()" />
                        <x-forms.input placeholder="/docker-compose.yaml" wire:model.defer="docker_compose_location"
                            label="Docker Compose Location" helper="It is calculated together with the Base Directory."
                            x-model="composeLocation" @blur="normalizeComposeLocation()" />
                        <div class="pt-2">
                            <span>
                                Compose file location in your repository: </span><span class='dark:text-warning'
                                x-text='(baseDir === "/" ? "" : baseDir) + (composeLocation.startsWith("/") ? composeLocation : "/" + composeLocation)'></span>
                        </div>
                    </div>
                @else
                    <x-forms.input wire:model="base_directory" label="Base Directory"
                        helper="Directory to use as root. Useful for monorepos." />
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

            {{-- Environment Variables Import Modal --}}
            @include('livewire.project.new.partials.env-import-modal')
        @endif
    </div>
</div>
