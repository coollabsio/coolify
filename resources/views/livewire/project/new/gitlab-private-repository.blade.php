<div>
    <div class="flex items-end gap-2">
        <h1>Create a new Application</h1>
        <x-modal-input buttonTitle="+ Add GitLab App" title="New GitLab App" closeOutside="false">
            <livewire:source.gitlab.create />
        </x-modal-input>
        @if ($repositories->count() > 0 && $gitlab_app_id)
            <x-forms.button wire:click.prevent="loadRepositories({{ $gitlab_app_id }})">
                Refresh Repository List
            </x-forms.button>
        @endif
    </div>
    <div class="pb-4">Deploy any public or private Git repositories through a GitLab App.</div>
    @if ($gitlab_apps->count() !== 0)
        <div class="flex flex-col gap-2">
            @if ($current_step === 'gitlab_apps')
                <h2 class="pt-4 pb-4">Select a GitLab App</h2>
                <div class="flex flex-col justify-center gap-2 text-left">
                    @foreach ($gitlab_apps as $glapp)
                        <div class="flex">
                            <div class="w-full gap-2 py-4 group coolbox"
                                wire:click.prevent="loadRepositories({{ $glapp->id }})"
                                wire:key="{{ $glapp->id }}">
                                <div class="flex mr-4">
                                    <div class="flex flex-col mx-6">
                                        <div class="box-title">
                                            {{ data_get($glapp, 'name') }}
                                        </div>
                                        <div class="box-description">
                                            {{ data_get($glapp, 'html_url') }}</div>
                                    </div>
                                </div>
                            </div>
                            <div class="flex flex-col items-center justify-center">
                                <x-loading wire:loading wire:target="loadRepositories({{ $glapp->id }})" />
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
            @if ($current_step === 'repository')
                @if ($repositories->count() > 0)
                    <div class="flex flex-col gap-2 pb-6">
                        <div class="flex gap-2">
                            <x-forms.datalist class="w-full" label="Repository" placeholder="Search repositories..." wire:model.live="selected_project_id">
                                @foreach ($repositories as $repo)
                                    <option value="{{ data_get($repo, 'id') }}">{{ data_get($repo, 'path_with_namespace') }}</option>
                                @endforeach
                            </x-forms.datalist>
                        </div>
                        <x-forms.button :showLoadingIndicator="false" wire:click.prevent="loadBranches" wire:target="loadBranches, selected_project_id">
                            Load Repository
                            <x-loading-on-button wire:loading.delay wire:target="loadBranches, selected_project_id" />
                        </x-forms.button>
                    </div>
                @else
                    <div>No repositories found. Check your GitLab App configuration.</div>
                @endif
                @if ($branches->count() > 0)
                    <h2 class="text-lg font-bold">Configuration</h2>
                    <div class="flex flex-col gap-2 pb-6">
                        <form class="flex flex-col" wire:submit='submit'>
                            <div class="flex flex-col gap-2 pb-6">
                                <div class="flex gap-2">
                                    <x-forms.select id="selected_branch_name" label="Branch">
                                        <option value="default" disabled selected>Select a branch</option>
                                        @foreach ($branches as $branch)
                                            @if ($loop->first)
                                                <option selected value="{{ data_get($branch, 'name') }}">
                                                    {{ data_get($branch, 'name') }}
                                                </option>
                                            @else
                                                <option value="{{ data_get($branch, 'name') }}">
                                                    {{ data_get($branch, 'name') }}
                                                </option>
                                            @endif
                                        @endforeach
                                    </x-forms.select>
                                    <x-forms.select wire:model.live="build_pack" label="Build Pack" required>
                                        <option value="nixpacks">Nixpacks</option>
                                        <option value="railpack">Railpack (Beta)</option>
                                        <option value="static">Static</option>
                                        <option value="dockerfile">Dockerfile</option>
                                        <option value="dockercompose">Docker Compose</option>
                                    </x-forms.select>
                                    @if ($is_static)
                                        <x-forms.input id="publish_directory" label="Publish Directory"
                                            helper="If there is a build process involved (like Svelte, React, Next, etc..), please specify the output directory for the build assets." />
                                    @endif
                                </div>
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
                                        <x-forms.input placeholder="/" wire:model.defer="base_directory"
                                            label="Base Directory"
                                            helper="Directory to use as root. Useful for monorepos." x-model="baseDir"
                                            @blur="normalizeBaseDir()" />
                                        <x-forms.input placeholder="/docker-compose.yaml"
                                            wire:model.defer="docker_compose_location" label="Docker Compose Location"
                                            helper="It is calculated together with the Base Directory."
                                            x-model="composeLocation" @blur="normalizeComposeLocation()" />
                                        <div class="pt-2">
                                            <span>
                                                Compose file location in your repository: </span><span
                                                class='dark:text-warning'
                                                x-text='(baseDir === "/" ? "" : baseDir) + (composeLocation.startsWith("/") ? composeLocation : "/" + composeLocation)'></span>
                                        </div>
                                    </div>
                                @else
                                    <x-forms.input wire:model="base_directory" label="Base Directory"
                                        helper="Directory to use as root. Useful for monorepos." />
                                @endif
                                @if ($show_is_static)
                                    <x-forms.input type="number" id="port" label="Port" :readonly="$is_static || $build_pack === 'static'"
                                        helper="The port your application listens on." />
                                    <div class="w-52">
                                        <x-forms.checkbox instantSave id="is_static" label="Is it a static site?"
                                            helper="If your application is a static site or the final build assets should be served as a static site, enable this." />
                                    </div>
                                @endif
                            </div>
                            <x-forms.button type="submit">
                                Continue
                            </x-forms.button>
                        </form>
                    </div>
                @endif
            @endif
        </div>
    @else
        <div class="hero">
            No connected GitLab Application found. Please <a href="/sources" class="underline">add a GitLab App</a> first.
        </div>
    @endif
</div>
