<div>
    <div class="flex items-end gap-2">
        <h1>{{ __('application.create_new_application') }}</h1>
        <x-modal-input buttonTitle="{{ __('button.add_github_app') }}" title="{{ __('modal.new_github_app') }}" closeOutside="false">
            <livewire:source.github.create />
        </x-modal-input>
        @if ($repositories->count() > 0)
            <a target="_blank" class="flex hover:no-underline" href="{{ getInstallationPath($github_app) }}">
                <x-forms.button>
                    {{ __('application.change_repositories_on_github') }}
                    <x-external-link />
                </x-forms.button>
            </a>
        @endif
    </div>
    <div class="pb-4">{{ __('project.deploy_github_app_desc') }}</div>
    @if ($github_apps->count() !== 0)
        <div class="flex flex-col gap-2">
            @if ($current_step === 'github_apps')
                <h2 class="pt-4 pb-4">{{ __('application.select_github_app') }}</h2>
                <div class="flex flex-col justify-center gap-2 text-left">
                    @foreach ($github_apps as $ghapp)
                        <div class="flex">
                            <div class="w-full gap-2 py-4 group coolbox"
                                wire:click.prevent="loadRepositories({{ $ghapp->id }})"
                                wire:key="{{ $ghapp->id }}">
                                <div class="flex mr-4">
                                    <div class="flex flex-col mx-6">
                                        <div class="box-title">
                                            {{ data_get($ghapp, 'name') }}
                                        </div>
                                        <div class="box-description">
                                            {{ data_get($ghapp, 'html_url') }}</div>
                                    </div>
                                </div>
                            </div>
                            <div class="flex flex-col items-center justify-center">
                                <x-loading wire:loading wire:target="loadRepositories({{ $ghapp->id }})" />
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
            @if ($current_step === 'repository')
                @if ($repositories->count() > 0)
                    <div class="flex flex-col gap-2 pb-6">
                        <div class="flex gap-2">
                            <x-forms.select class="w-full" label="{{ __('application.repository') }}" wire:model="selected_repository_id">
                                @foreach ($repositories as $repo)
                                    @if ($loop->first)
                                        <option selected value="{{ data_get($repo, 'id') }}">
                                            {{ data_get($repo, 'name') }}
                                        </option>
                                    @else
                                        <option value="{{ data_get($repo, 'id') }}">{{ data_get($repo, 'name') }}
                                        </option>
                                    @endif
                                @endforeach
                            </x-forms.select>
                        </div>
                        <x-forms.button wire:click.prevent="loadBranches">{{ __('button.load_repository') }}</x-forms.button>
                    </div>
                @else
                    <div>{{ __('application.no_repositories_found') }}</div>
                @endif
                @if ($branches->count() > 0)
                    <h2 class="text-lg font-bold">{{ __('common.configuration') }}</h2>
                    <div class="flex flex-col gap-2 pb-6">
                        <form class="flex flex-col" wire:submit='submit'>
                            <div class="flex flex-col gap-2 pb-6">
                                <div class="flex gap-2">
                                    <x-forms.select id="selected_branch_name" label="{{ __('application.branch') }}">
                                        <option value="default" disabled selected>{{ __('application.select_a_branch') }}</option>
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
                                    <x-forms.select wire:model.live="build_pack" label="{{ __('application.build_pack') }}" required>
                                        <option value="nixpacks">Nixpacks</option>
                                        <option value="static">Static</option>
                                        <option value="dockerfile">Dockerfile</option>
                                        <option value="dockercompose">{{ __('application.docker_compose') }}</option>
                                    </x-forms.select>
                                    @if ($is_static)
                                        <x-forms.input id="publish_directory" label="{{ __('application.publish_directory') }}"
                                            helper="{{ __('application.publish_directory_helper') }}" />
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
                                        <x-forms.input placeholder="/" wire:model.defer="base_directory"
                                            label="{{ __('application.base_directory') }}"
                                            helper="{{ __('application.base_directory_helper') }}" x-model="baseDir"
                                            @blur="normalizeBaseDir()" />
                                        <x-forms.input placeholder="/docker-compose.yaml"
                                            wire:model.defer="docker_compose_location" label="{{ __('application.docker_compose_location') }}"
                                            helper="{{ __('application.docker_compose_location_helper') }}"
                                            x-model="composeLocation" @blur="normalizeComposeLocation()" />
                                        <div class="pt-2">
                                            <span>
                                                {{ __('application.compose_file_location_in_repository') }} </span><span
                                                class='dark:text-warning'
                                                x-text='(baseDir === "/" ? "" : baseDir) + (composeLocation.startsWith("/") ? composeLocation : "/" + composeLocation)'></span>
                                        </div>
                                    </div>
                                @else
                                    <x-forms.input wire:model="base_directory" label="{{ __('application.base_directory') }}"
                                        helper="{{ __('application.base_directory_helper') }}" />
                                @endif
                                @if ($show_is_static)
                                    <x-forms.input type="number" id="port" label="{{ __('application.port') }}" :readonly="$is_static || $build_pack === 'static'"
                                        helper="{{ __('application.port_helper') }}" />
                                    <div class="w-52">
                                        <x-forms.checkbox instantSave id="is_static" label="{{ __('application.is_static_site') }}"
                                            helper="{{ __('application.is_static_site_helper') }}" />
                                    </div>
                                @endif
                            </div>
                            <x-forms.button type="submit">
                                {{ __('button.continue') }}
                            </x-forms.button>
                @endif
            @endif
        </div>
    @else
        <div class="hero">
            {{ __('application.no_github_application_found') }}
        </div>
    @endif
</div>
