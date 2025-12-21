<div x-data x-init="$nextTick(() => { if ($refs.autofocusInput) $refs.autofocusInput.focus(); })">
    <h1>{{ __('application.create_new_application') }}</h1>
    <div class="pb-8">{{ __('application.deploy_public_git_repositories') }}</div>

    <!-- Repository URL Form -->
    <form class="flex flex-col gap-2" wire:submit='loadBranch'>
        <div class="flex flex-col gap-2">
            <div class="flex gap-2 items-end">
                <x-forms.input required id="repository_url" label="{{ __('application.repository_url_https') }}"
                    helper="{!! __('repository.url') !!}" autofocus />
                <x-forms.button type="submit">
                    {{ __('application.check_repository') }}
                </x-forms.button>
            </div>
            <div>
                {{ __('application.for_example_applications') }} <a class="underline dark:text-white"
                    href="https://github.com/coollabsio/coolify-examples/" target="_blank">Coolify
                    Examples</a>。
            </div>
        </div>
    </form>

    @if ($branchFound)
        @if ($rate_limit_remaining && $rate_limit_reset)
            <div class="flex gap-2 py-2">
                <div>{{ __('application.rate_limit') }}</div>
                <x-helper
                    helper="{{ __('application.rate_limit_remaining') }} {{ $rate_limit_remaining }}<br>{{ __('application.rate_limit_reset') }} {{ $rate_limit_reset }} UTC" />
            </div>
        @endif

        <!-- Application Configuration Form -->
        <form class="flex flex-col gap-2 pt-4" wire:submit='submit'>
            <div class="flex flex-col gap-2 pb-6">
                <div class="flex gap-2">
                    @if ($git_source === 'other')
                        <x-forms.input id="git_branch" label="{{ __('application.branch') }}"
                            helper="{{ __('application.you_can_select_other_branches') }}" />
                    @else
                        <x-forms.input disabled id="git_branch" label="{{ __('application.branch') }}"
                            helper="{{ __('application.you_can_select_other_branches') }}" />
                    @endif
                    <x-forms.select wire:model.live="build_pack" label="{{ __('application.build_pack') }}" required>
                        <option value="nixpacks">Nixpacks</option>
                        <option value="static">Static</option>
                        <option value="dockerfile">Dockerfile</option>
                        <option value="dockercompose">{{ __('application.docker_compose') }}</option>
                    </x-forms.select>
                    @if ($isStatic)
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
                        <x-forms.input placeholder="/" wire:model.defer="base_directory" label="{{ __('application.base_directory') }}"
                            helper="{{ __('application.base_directory_helper') }}" x-model="baseDir"
                            @blur="normalizeBaseDir()" />
                        <x-forms.input placeholder="/docker-compose.yaml" wire:model.defer="docker_compose_location"
                            label="{{ __('application.docker_compose_location') }}" helper="{{ __('application.docker_compose_location_helper') }}"
                            x-model="composeLocation" @blur="normalizeComposeLocation()" />
                        <div class="pt-2">
                            <span>
                                {{ __('application.compose_file_location') }} </span><span class='dark:text-warning'
                                x-text='(baseDir === "/" ? "" : baseDir) + (composeLocation.startsWith("/") ? composeLocation : "/" + composeLocation)'></span>
                        </div>
                    </div>
                @else
                    <x-forms.input wire:model="base_directory" label="{{ __('application.base_directory') }}"
                        helper="{{ __('application.base_directory_helper') }}" />
                @endif
                @if ($show_is_static)
                    <x-forms.input type="number" id="port" label="{{ __('application.port') }}" :readonly="$isStatic || $build_pack === 'static'"
                        helper="{{ __('application.port_helper') }}" />
                    <div class="w-64">
                        <x-forms.checkbox instantSave id="isStatic" label="{{ __('application.is_static_site') }}"
                            helper="{{ __('application.is_static_site_helper') }}" />
                    </div>
                @endif
            </div>
            <x-forms.button type="submit">
                {{ __('common.continue') }}
            </x-forms.button>
        </form>
    @endif
</div>
