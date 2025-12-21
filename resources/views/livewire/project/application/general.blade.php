<div x-data="{
    initLoadingCompose: $wire.entangle('initLoadingCompose'),
    canUpdate: @js(auth()->user()->can('update', $application)),
    shouldDisable() {
        return this.initLoadingCompose || !this.canUpdate;
    }
}">
    <form wire:submit='submit' class="flex flex-col pb-32">
        <div class="flex items-center gap-2">
            <h2>{{ __('menu.general') }}</h2>
            @if (isDev())
                <div>{{ $application->compose_parsing_version }}</div>
            @endif
            <x-forms.button canGate="update" :canResource="$application" type="submit">{{ __('button.save') }}</x-forms.button>
            @if ($application->build_pack === 'dockercompose')
                <x-forms.button canGate="update" :canResource="$application" wire:target='initLoadingCompose'
                    x-on:click="$wire.dispatch('loadCompose', false)">
                    {{ $application->docker_compose_raw ? __('application.reload_compose_file') : __('application.load_compose_file') }}
                </x-forms.button>
            @endif
        </div>
        <div>{{ __('application.general_config_desc') }}</div>
        <div class="flex flex-col gap-2 py-4">
            <div class="flex flex-col items-end gap-2 xl:flex-row">
                <x-forms.input x-bind:disabled="shouldDisable()" id="name" label="{{ __('common.name') }}" required />
                <x-forms.input x-bind:disabled="shouldDisable()" id="description" label="{{ __('common.description') }}" />
            </div>

            @if (!$application->dockerfile && $application->build_pack !== 'dockerimage')
                <div class="flex flex-col gap-2">
                    <div class="flex gap-2">
                        <x-forms.select x-bind:disabled="shouldDisable()" wire:model.live="buildPack" label="{{ __('application.build_pack') }}"
                            required>
                            <option value="nixpacks">Nixpacks</option>
                            <option value="static">Static</option>
                            <option value="dockerfile">Dockerfile</option>
                            <option value="dockercompose">Docker Compose</option>
                        </x-forms.select>
                        @if ($application->settings->is_static || $application->build_pack === 'static')
                            <x-forms.select x-bind:disabled="!canUpdate" id="staticImage" label="{{ __('application.static_image') }}" required>
                                <option value="nginx:alpine">nginx:alpine</option>
                                <option disabled value="apache:alpine">apache:alpine</option>
                            </x-forms.select>
                        @endif
                    </div>

                    @if ($application->build_pack === 'dockercompose')
                        @if (
                                !is_null($parsedServices) &&
                                count($parsedServices) > 0 &&
                                !$application->settings->is_raw_compose_deployment_enabled
                            )
                            <h3 class="pt-6">{{ __('application.domains') }}</h3>
                            @foreach (data_get($parsedServices, 'services') as $serviceName => $service)
                                @if (!isDatabaseImage(data_get($service, 'image')))
                                    <div class="flex items-end gap-2">
                                        <x-forms.input
                                            helper="{{ __('application.domains_helper') }}"
                                            label="{{ __('application.domains_for', ['name' => $serviceName]) }}"
                                            id="parsedServiceDomains.{{ str($serviceName)->replace('-', '_')->replace('.', '_') }}.domain"
                                            x-bind:disabled="shouldDisable()"></x-forms.input>
                                        @can('update', $application)
                                            <x-forms.button wire:click="generateDomain('{{ $serviceName }}')">{{ __('application.generate_domain') }}</x-forms.button>
                                        @endcan
                                    </div>
                                @endif
                            @endforeach
                        @endif
                    @endif

                </div>
            @endif
            @if ($application->settings->is_static || $application->build_pack === 'static')
                <x-forms.textarea id="customNginxConfiguration"
                    placeholder="{{ __('application.custom_nginx_placeholder') }}" label="{{ __('application.custom_nginx_config') }}"
                    helper="{{ __('application.custom_nginx_helper') }}" x-bind:disabled="!canUpdate" />
                @can('update', $application)
                    <x-modal-confirmation title="{{ __('application.generate_nginx_config_title') }}"
                        buttonTitle="{{ __('application.generate_nginx_config_button') }}" buttonFullWidth
                        submitAction="generateNginxConfiguration('{{ $application->settings->is_spa ? 'spa' : 'static' }}')"
                        :actions="[
                        __('application.generate_nginx_action_1'),
                        __('application.generate_nginx_action_2') . ' (' .
                        ($application->settings->is_spa ? 'SPA' : 'static') .
                        ').',
                    ]" />
                @endcan
            @endif
            <div class="w-96 pb-6">
                @if ($application->could_set_build_commands())
                    <x-forms.checkbox instantSave id="isStatic" label="{{ __('application.is_static') }}"
                        helper="{{ __('application.is_static_helper') }}"
                        x-bind:disabled="!canUpdate" />
                @endif
                @if ($application->settings->is_static && $application->build_pack !== 'static')
                    <x-forms.checkbox label="{{ __('application.is_spa') }}"
                        helper="{{ __('application.is_spa_helper') }}" id="isSpa" instantSave
                        x-bind:disabled="!canUpdate"></x-forms.checkbox>
                @endif
            </div>
            @if ($application->build_pack !== 'dockercompose')
                <div class="flex items-end gap-2">
                    @if ($application->settings->is_container_label_readonly_enabled == false)
                        <x-forms.input placeholder="https://coolify.io" wire:model="fqdn" label="{{ __('application.domains') }}" readonly
                            helper="{{ __('application.readonly_labels_disabled') }}"
                            x-bind:disabled="!canUpdate" />
                    @else
                        <x-forms.input placeholder="https://coolify.io" wire:model="fqdn" label="{{ __('application.domains') }}"
                            helper="{{ __('application.domains_helper') }}"
                            x-bind:disabled="!canUpdate" />
                        @can('update', $application)
                            <x-forms.button wire:click="getWildcardDomain">{{ __('application.generate_domain') }}
                            </x-forms.button>
                        @endcan
                    @endif
                </div>
                <div class="flex items-end gap-2">
                    @if ($application->settings->is_container_label_readonly_enabled == false)
                        @if ($application->redirect === 'both')
                            <x-forms.input label="{{ __('application.direction') }}" value="{{ __('application.direction_both') }}" readonly
                                helper="{{ __('application.readonly_labels_disabled') }}"
                                x-bind:disabled="!canUpdate" />
                        @elseif ($application->redirect === 'www')
                            <x-forms.input label="{{ __('application.direction') }}" value="{{ __('application.direction_www') }}" readonly
                                helper="{{ __('application.readonly_labels_disabled') }}"
                                x-bind:disabled="!canUpdate" />
                        @elseif ($application->redirect === 'non-www')
                            <x-forms.input label="{{ __('application.direction') }}" value="{{ __('application.direction_non_www') }}" readonly
                                helper="{{ __('application.readonly_labels_disabled') }}"
                                x-bind:disabled="!canUpdate" />
                        @endif
                    @else
                        <x-forms.select label="{{ __('application.direction') }}" id="redirect" required
                            helper="{{ __('application.direction_helper') }}"
                            x-bind:disabled="!canUpdate">
                            <option value="both">{{ __('application.direction_both') }}</option>
                            <option value="www">{{ __('application.direction_www') }}</option>
                            <option value="non-www">{{ __('application.direction_non_www') }}</option>
                        </x-forms.select>
                        @if ($application->settings->is_container_label_readonly_enabled)
                            @can('update', $application)
                                <x-modal-confirmation title="{{ __('application.confirm_redirect_title') }}" buttonTitle="{{ __('application.set_direction') }}"
                                    submitAction="setRedirect" :actions="[__('application.redirect_action')]"
                                    confirmationText="{{ $application->fqdn . '/' }}"
                                    confirmationLabel="{{ __('application.confirm_application_url') }}"
                                    shortConfirmationLabel="{{ __('application.application_url') }}" :confirmWithPassword="false"
                                    step2ButtonText="{{ __('application.set_direction') }}">
                                    <x-slot:customButton>
                                        <div class="w-[7.2rem]">{{ __('application.set_direction') }}</div>
                                    </x-slot:customButton>
                                </x-modal-confirmation>
                            @endcan
                        @endif
                    @endif
                </div>
            @endif

            @if ($application->build_pack !== 'dockercompose')
                <div class="flex items-center gap-2 pt-8">
                    <h3>{{ __('application.docker_registry') }}</h3>
                    @if ($application->build_pack !== 'dockerimage' && !$application->destination->server->isSwarm())
                        <x-helper
                            helper="{{ __('application.docker_registry_helper') }}" />
                    @endif
                </div>
                @if ($application->destination->server->isSwarm())
                    @if ($application->build_pack !== 'dockerimage')
                        <div>{!! __('application.swarm_registry_required') !!}</div>
                    @endif
                @endif
                <div class="flex flex-col gap-2 xl:flex-row">
                    @if ($application->build_pack === 'dockerimage')
                        @if ($application->destination->server->isSwarm())
                            <x-forms.input required id="dockerRegistryImageName" label="{{ __('application.docker_image') }}"
                                x-bind:disabled="!canUpdate" />
                            <x-forms.input id="dockerRegistryImageTag" label="{{ __('application.docker_image_tag') }}"
                                helper="{{ __('application.docker_image_tag_helper') }}"
                                x-bind:disabled="!canUpdate" />
                        @else
                            <x-forms.input id="dockerRegistryImageName" label="{{ __('application.docker_image') }}" x-bind:disabled="!canUpdate" />
                            <x-forms.input id="dockerRegistryImageTag" label="{{ __('application.docker_image_tag') }}"
                                helper="{{ __('application.docker_image_tag_helper') }}"
                                x-bind:disabled="!canUpdate" />
                        @endif
                    @else
                        @if (
                                $application->destination->server->isSwarm() ||
                                $application->additional_servers->count() > 0 ||
                                $application->settings->is_build_server_enabled
                            )
                            <x-forms.input id="dockerRegistryImageName" required label="{{ __('application.docker_image') }}" placeholder="{{ __('application.docker_image_required') }}"
                                x-bind:disabled="!canUpdate" />
                            <x-forms.input id="dockerRegistryImageTag"
                                helper="{{ __('application.docker_image_tag_helper_2') }}"
                                placeholder="{{ __('application.docker_image_tag_placeholder_2') }}" label="{{ __('application.docker_image_tag') }}"
                                x-bind:disabled="!canUpdate" />
                        @else
                            <x-forms.input id="dockerRegistryImageName"
                                helper="{{ __('application.docker_image_helper') }}"
                                placeholder="{{ __('application.docker_image_placeholder') }}" label="{{ __('application.docker_image') }}"
                                x-bind:disabled="!canUpdate" />
                            <x-forms.input id="dockerRegistryImageTag" placeholder="{{ __('application.docker_image_tag_placeholder') }}"
                                helper="{{ __('application.docker_image_tag_helper_2') }}"
                                label="{{ __('application.docker_image_tag') }}" x-bind:disabled="!canUpdate" />
                        @endif
                    @endif
                </div>
            @endif
            <div>
                <h3>{{ __('application.build') }}</h3>
                @if ($application->build_pack === 'dockerimage')
                    <x-forms.input
                        helper="{{ __('application.custom_docker_options_helper') }}"
                        placeholder="{{ __('application.custom_docker_options_placeholder') }}"
                        id="customDockerRunOptions" label="{{ __('application.custom_docker_options') }}" x-bind:disabled="!canUpdate" />
                @else
                    @if ($application->could_set_build_commands())
                        @if ($application->build_pack === 'nixpacks')
                            <div class="flex flex-col gap-2 xl:flex-row">
                                <x-forms.input helper="{{ __('application.nixpacks_modify_helper') }}"
                                    id="installCommand" label="{{ __('application.install_command') }}" x-bind:disabled="!canUpdate" />
                                <x-forms.input helper="{{ __('application.nixpacks_modify_helper') }}"
                                    id="buildCommand" label="{{ __('application.build_command') }}" x-bind:disabled="!canUpdate" />
                                <x-forms.input helper="{{ __('application.nixpacks_modify_helper') }}"
                                    id="startCommand" label="{{ __('application.start_command') }}" x-bind:disabled="!canUpdate" />
                            </div>
                            <div class="pt-1 text-xs">{{ __('application.nixpacks_detect') }}
                                <a class="underline" href="https://coolify.io/docs/applications/">{{ __('application.framework_docs') }}</a>
                            </div>
                        @endif

                    @endif
                    <div class="flex flex-col gap-2 pt-6 pb-10">
                        @if ($application->build_pack === 'dockercompose')
                                <div class="flex flex-col gap-2" @can('update', $application) x-init="$wire.dispatch('loadCompose', true)" @endcan>
                                    <div x-data="{
                                        baseDir: '{{ $application->base_directory }}',
                                        composeLocation: '{{ $application->docker_compose_location }}',
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
                                    }" class="flex gap-2">
                                        <x-forms.input x-bind:disabled="shouldDisable()" placeholder="/" wire:model.defer="baseDirectory"
                                            label="{{ __('application.base_directory') }}" helper="{{ __('application.base_directory_helper') }}"
                                            x-model="baseDir" @blur="normalizeBaseDir()" />
                                        <x-forms.input x-bind:disabled="shouldDisable()" placeholder="{{ __('application.docker_compose_location_placeholder') }}"
                                            wire:model.defer="dockerComposeLocation" label="{{ __('application.docker_compose_location') }}"
                                            helper="{{ __('application.docker_compose_location_helper') }}<br><span class='dark:text-warning'>{{ Str::start($application->base_directory . $application->docker_compose_location, '/') }}</span>"
                                            x-model="composeLocation" @blur="normalizeComposeLocation()" />
                                    </div>
                                    <div class="w-96">
                                        <x-forms.checkbox instantSave id="isPreserveRepositoryEnabled"
                                            label="{{ __('application.preserve_repository') }}"
                                            helper="{{ __('application.preserve_repository_helper') }}"
                                            x-bind:disabled="shouldDisable()" />
                                    </div>
                                    <div class="pt-4">{{ __('application.advanced_commands_warning') }}</div>
                                    <div class="flex gap-2">
                                        <x-forms.input x-bind:disabled="shouldDisable()" placeholder="{{ __('application.custom_build_command_placeholder') }}"
                                            id="dockerComposeCustomBuildCommand"
                                            helper="{{ __('application.custom_build_command_helper') }}"
                                            label="{{ __('application.custom_build_command') }}" />
                                        <x-forms.input x-bind:disabled="shouldDisable()" placeholder="{{ __('application.custom_start_command_placeholder') }}"
                                            id="dockerComposeCustomStartCommand"
                                            helper="{{ __('application.custom_start_command_helper') }}"
                                            label="{{ __('application.custom_start_command') }}" />
                                    </div>
                                    @if ($this->dockerComposeCustomBuildCommand)
                                        <div wire:key="docker-compose-build-preview">
                                            <x-forms.input readonly value="{{ $this->dockerComposeBuildCommandPreview }}"
                                                label="{{ __('application.final_build_command_preview') }}"
                                                helper="{{ __('application.final_build_command_helper') }}" />
                                        </div>
                                    @endif
                                    @if ($this->dockerComposeCustomStartCommand)
                                        <div wire:key="docker-compose-start-preview">
                                            <x-forms.input readonly value="{{ $this->dockerComposeStartCommandPreview }}"
                                                label="{{ __('application.final_start_command_preview') }}"
                                                helper="{{ __('application.final_start_command_helper') }}" />
                                        </div>
                                    @endif
                                    @if ($this->application->is_github_based() && !$this->application->is_public_repository())
                                        <div class="pt-4">
                                            <x-forms.textarea
                                                helper="{{ __('application.watch_paths_helper') }}"
                                                placeholder="{{ __('application.watch_paths_placeholder') }}" id="watchPaths" label="{{ __('application.watch_paths') }}"
                                                x-bind:disabled="shouldDisable()" />
                                        </div>
                                    @endif
                                </div>
                        @else
                                <div x-data="{
                                    baseDir: '{{ $application->base_directory }}',
                                    dockerfileLocation: '{{ $application->dockerfile_location }}',
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
                                    normalizeDockerfileLocation() {
                                        this.dockerfileLocation = this.normalizePath(this.dockerfileLocation);
                                    }
                                }" class="flex flex-col gap-2 xl:flex-row">
                                    <x-forms.input placeholder="/" wire:model.defer="baseDirectory" label="{{ __('application.base_directory') }}"
                                        helper="{{ __('application.base_directory_helper') }}" x-bind:disabled="!canUpdate"
                                        x-model="baseDir" @blur="normalizeBaseDir()" />
                                    @if ($application->build_pack === 'dockerfile' && !$application->dockerfile)
                                        <x-forms.input placeholder="{{ __('application.dockerfile_location_placeholder') }}" wire:model.defer="dockerfileLocation" label="{{ __('application.dockerfile_location') }}"
                                            helper="{{ __('application.dockerfile_location_helper') }}:<br><span class='dark:text-warning'>{{ Str::start($application->base_directory . $application->dockerfile_location, '/') }}</span>"
                                            x-bind:disabled="!canUpdate" x-model="dockerfileLocation" @blur="normalizeDockerfileLocation()" />
                                    @endif

                                    @if ($application->build_pack === 'dockerfile')
                                        <x-forms.input id="dockerfileTargetBuild" label="{{ __('application.docker_build_target') }}"
                                            helper="{{ __('application.docker_build_target_helper') }}" x-bind:disabled="!canUpdate" />
                                    @endif
                                    @if ($application->could_set_build_commands())
                                        @if ($application->settings->is_static)
                                            <x-forms.input placeholder="{{ __('application.publish_directory_placeholder') }}" id="publishDirectory" label="{{ __('application.publish_directory') }}" required
                                                x-bind:disabled="!canUpdate" />
                                        @else
                                            <x-forms.input placeholder="{{ __('application.publish_directory_placeholder_root') }}" id="publishDirectory" label="{{ __('application.publish_directory') }}"
                                                x-bind:disabled="!canUpdate" />
                                        @endif
                                    @endif

                                </div>
                                @if ($this->application->is_github_based() && !$this->application->is_public_repository())
                                    <div class="pb-4">
                                        <x-forms.textarea
                                            helper="{{ __('application.watch_paths_helper') }}"
                                            placeholder="{{ __('application.watch_paths_placeholder_2') }}" id="watchPaths" label="{{ __('application.watch_paths') }}"
                                            x-bind:disabled="!canUpdate" />
                                    </div>
                                @endif
                                <x-forms.input
                                    helper="{{ __('application.custom_docker_options_helper') }}"
                                    placeholder="{{ __('application.custom_docker_options_placeholder') }}"
                                    id="customDockerRunOptions" label="{{ __('application.custom_docker_options') }}" x-bind:disabled="!canUpdate" />

                                @if ($application->build_pack !== 'dockercompose')
                                    <div class="pt-2 w-96">
                                        <x-forms.checkbox
                                            helper="{{ __('application.use_build_server_helper') }}"
                                            instantSave id="isBuildServerEnabled" label="{{ __('application.use_build_server') }}"
                                            x-bind:disabled="!canUpdate" />
                                    </div>
                                @endif
                            @endif
                        </div>
                @endif
                </div>
                @if ($application->build_pack === 'dockercompose')
                    <div x-data="{ showRaw: true }">
                        <div class="flex items-center gap-2">
                            <h3>{{ __('application.docker_compose') }}</h3>
                            <x-forms.button x-show="!($application->settings->is_raw_compose_deployment_enabled)" @click.prevent="showRaw = !showRaw" x-text="showRaw ? '{{ __('application.show_deployable_compose') }}' : '{{ __('application.show_raw_compose') }}'"></x-forms.button>
                        </div>
                    @if ($application->settings->is_raw_compose_deployment_enabled)
                        <x-forms.textarea rows="10" readonly id="dockerComposeRaw"
                            label="{{ __('application.docker_compose_applicationid', ['id' => $application->id]) }}"
                            helper="{{ __('application.docker_compose_modify_git') }}"
                            monacoEditorLanguage="yaml" useMonacoEditor />
                    @else
                        @if ((int) $application->compose_parsing_version >= 3)
                            <div x-show="showRaw">
                                <x-forms.textarea rows="10" readonly id="dockerComposeRaw" label="{{ __('application.docker_compose_raw_content') }}"
                                    helper="{{ __('application.docker_compose_modify_git') }}"
                                    monacoEditorLanguage="yaml" useMonacoEditor />
                            </div>
                        @endif
                        <div x-show="showRaw === false">
                            <x-forms.textarea rows="10" readonly id="dockerCompose" label="{{ __('application.docker_compose_content') }}"
                                helper="{{ __('application.docker_compose_modify_git') }}"
                                monacoEditorLanguage="yaml" useMonacoEditor />
                        </div>
                    @endif
                    <div class="w-96">
                        <x-forms.checkbox label="{{ __('application.escape_special_chars') }}"
                            helper="{{ __('application.escape_special_chars_helper') }}"
                            id="isContainerLabelEscapeEnabled" instantSave x-bind:disabled="!canUpdate"></x-forms.checkbox>
                        {{-- <x-forms.checkbox label="Readonly labels"
                            helper="Labels are readonly by default. Readonly means that edits you do to the labels could be lost and Coolify will autogenerate the labels for you. If you want to edit the labels directly, disable this option. <br><br>Be careful, it could break the proxy configuration after you restart the container as Coolify will now NOT autogenerate the labels for you (ofc you can always reset the labels to the coolify defaults manually)."
                            id="isContainerLabelReadonlyEnabled" instantSave></x-forms.checkbox> --}}
                    </div>
                    </div>
                @endif
                @if ($application->dockerfile)
                    <x-forms.textarea label="{{ __('application.dockerfile') }}" id="dockerfile" monacoEditorLanguage="dockerfile" useMonacoEditor
                        rows="6" x-bind:disabled="!canUpdate"> </x-forms.textarea>
                @endif
                @if ($application->build_pack !== 'dockercompose')
                    <h3 class="pt-8">{{ __('application.network') }}</h3>
                    @if ($this->detectedPortInfo)
                        @if ($this->detectedPortInfo['isEmpty'])
                            <div
                                class="flex items-start gap-2 p-4 mb-4 text-sm rounded-lg bg-warning-50 dark:bg-warning-900/20 text-warning-800 dark:text-warning-300 border border-warning-200 dark:border-warning-800">
                                <svg class="w-5 h-5 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd"
                                        d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z"
                                        clip-rule="evenodd" />
                                </svg>
                                <div>
                                    <span class="font-semibold">{{ __('application.port_detected_warning') }}
                                        ({{ $this->detectedPortInfo['port'] }})</span>
                                    <p class="mt-1">{!! __('application.port_detected_isEmpty', ['port' => $this->detectedPortInfo['port']]) !!}</p>
                                </div>
                            </div>
                        @elseif (!$this->detectedPortInfo['matches'])
                            <div
                                class="flex items-start gap-2 p-4 mb-4 text-sm rounded-lg bg-warning-50 dark:bg-warning-900/20 text-warning-800 dark:text-warning-300 border border-warning-200 dark:border-warning-800">
                                <svg class="w-5 h-5 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd"
                                        d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z"
                                        clip-rule="evenodd" />
                                </svg>
                                <div>
                                    <span class="font-semibold">{{ __('application.port_mismatch_warning') }}</span>
                                    <p class="mt-1">{!! __('application.port_mismatch_detail', ['port' => $this->detectedPortInfo['port']]) !!}</p>
                                </div>
                            </div>
                        @else
                            <div
                                class="flex items-start gap-2 p-4 mb-4 text-sm rounded-lg bg-blue-50 dark:bg-blue-900/20 text-blue-800 dark:text-blue-300 border border-blue-200 dark:border-blue-800">
                                <svg class="w-5 h-5 shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd"
                                        d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z"
                                        clip-rule="evenodd" />
                                </svg>
                                <div>
                                    <span class="font-semibold">{{ __('application.port_configured_ok') }}</span>
                                    <p class="mt-1">{{ __('application.port_configured_ok_detail', ['port' => $this->detectedPortInfo['port']]) }}</p>
                                </div>
                            </div>
                        @endif
                    @endif
                    <div class="flex flex-col gap-2 xl:flex-row">
                        @if ($application->settings->is_static || $application->build_pack === 'static')
                            <x-forms.input id="portsExposes" label="{{ __('application.ports_exposes') }}" readonly x-bind:disabled="!canUpdate" />
                        @else
                            @if ($application->settings->is_container_label_readonly_enabled === false)
                                <x-forms.input placeholder="{{ __('application.ports_exposes_placeholder') }}" id="portsExposes" label="{{ __('application.ports_exposes') }}" readonly
                                    helper="{{ __('application.ports_exposes_readonly') }}"
                                    x-bind:disabled="!canUpdate" />
                            @else
                                <x-forms.input placeholder="{{ __('application.ports_exposes_placeholder') }}" id="portsExposes" label="{{ __('application.ports_exposes') }}" required
                                    helper="{{ __('application.ports_exposes_helper') }}"
                                    x-bind:disabled="!canUpdate" />
                            @endif
                        @endif
                        @if (!$application->destination->server->isSwarm())
                            <x-forms.input placeholder="{{ __('application.ports_mappings_placeholder') }}" id="portsMappings" label="{{ __('application.ports_mappings') }}"
                                helper="{{ __('application.ports_mappings_helper') }}"
                                x-bind:disabled="!canUpdate" />
                        @endif
                        @if (!$application->destination->server->isSwarm())
                            <x-forms.input id="customNetworkAliases" label="{{ __('application.network_aliases') }}"
                                helper="{{ __('application.network_aliases_helper') }}"
                                wire:model="customNetworkAliases" x-bind:disabled="!canUpdate" />
                        @endif
                    </div>

                    <h3 class="pt-8">{{ __('application.http_basic_auth') }}</h3>
                    <div>
                        <div class="w-96">
                            <x-forms.checkbox helper="{{ __('application.http_basic_auth_helper') }}" instantSave
                                label="{{ __('application.enable') }}" id="isHttpBasicAuthEnabled" x-bind:disabled="!canUpdate" />
                        </div>
                        @if ($application->is_http_basic_auth_enabled)
                            <div class="flex gap-2 py-2">
                                <x-forms.input id="httpBasicAuthUsername" label="{{ __('application.username') }}" required
                                    x-bind:disabled="!canUpdate" />
                                <x-forms.input id="httpBasicAuthPassword" type="password" label="{{ __('application.password') }}" required
                                    x-bind:disabled="!canUpdate" />
                            </div>
                        @endif
                    </div>

                    @if ($application->settings->is_container_label_readonly_enabled)
                        <x-forms.textarea readonly disabled label="{{ __('application.container_labels') }}" rows="15" id="customLabels"
                            monacoEditorLanguage="ini" useMonacoEditor x-bind:disabled="!canUpdate"></x-forms.textarea>
                    @else
                        <x-forms.textarea label="{{ __('application.container_labels') }}" rows="15" id="customLabels" monacoEditorLanguage="ini"
                            useMonacoEditor x-bind:disabled="!canUpdate"></x-forms.textarea>
                    @endif
                    <div class="w-96">
                        <x-forms.checkbox label="{{ __('application.readonly_labels') }}"
                            helper="{{ __('application.readonly_labels_helper') }}"
                            id="isContainerLabelReadonlyEnabled" instantSave
                            x-bind:disabled="!canUpdate"></x-forms.checkbox>
                        <x-forms.checkbox label="{{ __('application.escape_special_chars') }}"
                            helper="{{ __('application.escape_special_chars_helper') }}"
                            id="isContainerLabelEscapeEnabled" instantSave x-bind:disabled="!canUpdate"></x-forms.checkbox>
                    </div>
                    @can('update', $application)
                            <x-modal-confirmation title="{{ __('application.reset_labels_title') }}"
                                buttonTitle="{{ __('application.reset_labels_button') }}" buttonFullWidth submitAction="resetDefaultLabels(true)"
                                :actions="[
                            __('application.reset_labels_action_1'),
                            __('application.reset_labels_action_2'),
                        ]" confirmationText="{{ $application->fqdn . '/' }}"
                                confirmationLabel="{{ __('application.confirm_application_url') }}"
                                shortConfirmationLabel="{{ __('application.application_url') }}" :confirmWithPassword="false"
                                step2ButtonText="{{ __('application.permanently_reset_labels') }}" />
                    @endcan
                @endif

                <h3 class="pt-8">{{ __('application.pre_post_commands') }}</h3>
                <div class="flex flex-col gap-2 xl:flex-row">
                    <x-forms.input x-bind:disabled="shouldDisable()" placeholder="{{ __('application.pre_deployment_placeholder') }}"
                        id="preDeploymentCommand" label="{{ __('application.pre_deployment') }}"
                        helper="{{ __('application.pre_deployment_helper') }}" />
                    @if ($application->build_pack === 'dockercompose')
                        <x-forms.input x-bind:disabled="shouldDisable()" id="preDeploymentCommandContainer"
                            label="{{ __('application.container_name') }}"
                            helper="{{ __('application.container_name_helper') }}" />
                    @endif
                </div>
                <div class="flex flex-col gap-2 xl:flex-row">
                    <x-forms.input x-bind:disabled="shouldDisable()" placeholder="{{ __('application.post_deployment_placeholder') }}"
                        id="postDeploymentCommand" label="{{ __('application.post_deployment') }}"
                        helper="{{ __('application.post_deployment_helper') }}" />
                    @if ($application->build_pack === 'dockercompose')
                        <x-forms.input x-bind:disabled="shouldDisable()" id="postDeploymentCommandContainer"
                            label="{{ __('application.container_name') }}"
                            helper="{{ __('application.container_name_helper') }}" />
                    @endif
                </div>
            </div>
    </form>

    <x-domain-conflict-modal :conflicts="$domainConflicts" :showModal="$showDomainConflictModal"
        confirmAction="confirmDomainUsage" />

    @script
    <script>
        $wire.$on('loadCompose', (isInit = true) => {
            // Only load compose file if user has permission (this event should only be dispatched when authorized)
            $wire.initLoadingCompose = true;
            $wire.loadComposeFile(isInit);
        });
    </script>
    @endscript
</div>