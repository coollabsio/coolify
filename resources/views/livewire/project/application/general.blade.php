<div x-data="{ initLoadingCompose: $wire.entangle('initLoadingCompose') }">
    <form wire:submit='submit' class="flex flex-col pb-32">
        <div class="flex items-center gap-2">
            <h2>General</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
        {{--
            <x-forms.button wire:click="downloadConfig">
                Download Config
            <x-modal-input buttonTitle="Upload Config" title="Upload Config" :closeOutside="false">
                <livewire:project.shared.upload-config :applicationId="$application->id" />
            </x-modal-input>
 --}}
        </div>
        <div>General configuration for your application.</div>
        <div class="flex flex-col gap-2 py-4">
            <div class="flex flex-col items-end gap-2 xl:flex-row">
                <x-forms.input x-bind:disabled="initLoadingCompose" id="application.name" label="Name" required />
                <x-forms.input x-bind:disabled="initLoadingCompose" id="application.description" label="Description" />
            </div>

            @if (!$application->dockerfile && $application->build_pack !== 'dockerimage')
                <div class="flex flex-col gap-2">
                    <div class="flex gap-2">
                        <x-forms.select x-bind:disabled="initLoadingCompose" wire:model.live="application.build_pack"
                            label="Build Pack" required>
                            <option value="nixpacks">Nixpacks</option>
                            <option value="static">Static</option>
                            <option value="dockerfile">Dockerfile</option>
                            <option value="dockercompose">Docker Compose</option>
                        </x-forms.select>
                        @if ($application->settings->is_static || $application->build_pack === 'static')
                            <x-forms.select id="application.static_image" label="Static Image" required>
                                <option value="nginx:alpine">nginx:alpine</option>
                                <option disabled value="apache:alpine">apache:alpine</option>
                            </x-forms.select>
                        @endif
                    </div>

                    @if ($application->build_pack === 'dockercompose')
                        @if (
                            !is_null($parsedServices) &&
                                count($parsedServices) > 0 &&
                                !$application->settings->is_raw_compose_deployment_enabled)
                            <h3 class="pt-6">Domains</h3>
                            @foreach (data_get($parsedServices, 'services') as $serviceName => $service)
                                @if (!isDatabaseImage(data_get($service, 'image')))
                                    <div class="flex items-end gap-2">
                                        <x-forms.input
                                            helper="You can specify one domain with path or more with comma. You can specify a port to bind the domain to.<br><br><span class='text-helper'>Example</span><br>- http://app.coolify.io,https://cloud.coolify.io/dashboard<br>- http://app.coolify.io/api/v3<br>- http://app.coolify.io:3000 -> app.coolify.io will point to port 3000 inside the container. "
                                            label="Domains for {{ str($serviceName)->headline() }}"
                                            id="parsedServiceDomains.{{ $serviceName }}.domain"></x-forms.input>
                                        <x-forms.button wire:click="generateDomain('{{ $serviceName }}')">Generate
                                            Domain</x-forms.button>
                                    </div>
                                @endif
                            @endforeach
                        @endif
                    @endif

                </div>
            @endif
            @if ($application->build_pack !== 'dockercompose')
                <div class="flex items-end gap-2">
                    <x-forms.input placeholder="https://coolify.io" wire:model.blur="application.fqdn" label="Domains"
                        helper="You can specify one domain with path or more with comma. You can specify a port to bind the domain to.<br><br><span class='text-helper'>Example</span><br>- http://app.coolify.io,https://cloud.coolify.io/dashboard<br>- http://app.coolify.io/api/v3<br>- http://app.coolify.io:3000 -> app.coolify.io will point to port 3000 inside the container. " />
                    <x-forms.button wire:click="getWildcardDomain">Generate Domain
                    </x-forms.button>
                </div>
                <div class="flex items-end gap-2">
                    <x-forms.select label="Direction" id="application.redirect" required
                        helper="You must need to add www and non-www as an A DNS record.">
                        <option value="both">Allow www & non-www.</option>
                        <option value="www">Redirect to www.</option>
                        <option value="non-www">Redirect to non-www.</option>
                    </x-forms.select>
                    <x-modal-confirmation title="Confirm Redirection Setting?" buttonTitle="Set Direction"
                        submitAction="set_redirect" :actions="['All traffic will be redirected to the selected direction.']" confirmationText="{{ $application->fqdn . '/' }}"
                        confirmationLabel="Please confirm the execution of the action by entering the Application URL below"
                        shortConfirmationLabel="Application URL" :confirmWithPassword="false" step2ButtonText="Set Direction">
                        <x-slot:customButton>
                            <div class="w-[7.2rem]">Set Direction</div>
                        </x-slot:customButton>
                    </x-modal-confirmation>
                </div>
            @endif

            @if ($application->build_pack !== 'dockercompose')
                <div class="flex items-center gap-2 pt-8">
                    <h3>Docker Registry</h3>
                    @if ($application->build_pack !== 'dockerimage' && !$application->destination->server->isSwarm())
                        <x-helper
                            helper="Push the built image to a docker registry. More info <a class='underline' href='https://coolify.io/docs/knowledge-base/docker/registry' target='_blank'>here</a>." />
                    @endif
                </div>
                @if ($application->destination->server->isSwarm())
                    @if ($application->build_pack !== 'dockerimage')
                        <div>Docker Swarm requires the image to be available in a registry. More info <a
                                class="underline" href="https://coolify.io/docs/knowledge-base/docker/registry"
                                target="_blank">here</a>.</div>
                    @endif
                @endif
                <div class="flex flex-col gap-2 xl:flex-row">
                    @if ($application->build_pack === 'dockerimage')
                        @if ($application->destination->server->isSwarm())
                            <x-forms.input required id="application.docker_registry_image_name" label="Docker Image" />
                            <x-forms.input id="application.docker_registry_image_tag" label="Docker Image Tag" />
                        @else
                            <x-forms.input id="application.docker_registry_image_name" label="Docker Image" />
                            <x-forms.input id="application.docker_registry_image_tag" label="Docker Image Tag" />
                        @endif
                    @else
                        @if (
                            $application->destination->server->isSwarm() ||
                                $application->additional_servers->count() > 0 ||
                                $application->settings->is_build_server_enabled)
                            <x-forms.input id="application.docker_registry_image_name" required label="Docker Image"
                                placeholder="Required!" />
                            <x-forms.input id="application.docker_registry_image_tag"
                                helper="If set, it will tag the built image with this tag too. <br><br>Example: If you set it to 'latest', it will push the image with the commit sha tag + with the latest tag."
                                placeholder="Empty means latest will be used." label="Docker Image Tag" />
                        @else
                            <x-forms.input id="application.docker_registry_image_name"
                                helper="Empty means it won't push the image to a docker registry."
                                placeholder="Empty means it won't push the image to a docker registry."
                                label="Docker Image" />
                            <x-forms.input id="application.docker_registry_image_tag"
                                placeholder="Empty means only push commit sha tag."
                                helper="If set, it will tag the built image with this tag too. <br><br>Example: If you set it to 'latest', it will push the image with the commit sha tag + with the latest tag."
                                label="Docker Image Tag" />
                        @endif
                    @endif
                </div>
            @endif
            <div class="py-4 border-b dark:border-coolgray-200">
                <h3>Build</h3>
                @if ($application->build_pack === 'dockerimage')
                    <x-forms.input
                        helper="You can add custom docker run options that will be used when your container is started.<br>Note: Not all options are supported, as they could mess up Coolify's automation and could cause bad experience for users.<br><br>Check the <a class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/custom-commands'>docs.</a>"
                        placeholder="--cap-add SYS_ADMIN --device=/dev/fuse --security-opt apparmor:unconfined --ulimit nofile=1024:1024 --tmpfs /run:rw,noexec,nosuid,size=65536k"
                        id="application.custom_docker_run_options" label="Custom Docker Options" />
                @else
                    @if ($application->could_set_build_commands())
                        @if ($application->build_pack === 'nixpacks')
                            <div class="flex flex-col gap-2 xl:flex-row">
                                <x-forms.input helper="If you modify this, you probably need to have a nixpacks.toml"
                                    id="application.install_command" label="Install Command" />
                                <x-forms.input helper="If you modify this, you probably need to have a nixpacks.toml"
                                    id="application.build_command" label="Build Command" />
                                <x-forms.input helper="If you modify this, you probably need to have a nixpacks.toml"
                                    id="application.start_command" label="Start Command" />
                            </div>
                            <div class="pt-1 text-xs">Nixpacks will detect the required configuration
                                automatically.
                                <a class="underline"
                                    href="https://coolify.io/docs/resources/applications/index">Framework
                                    Specific Docs</a>
                            </div>
                        @endif

                    @endif
                    <div class="flex flex-col gap-2 pt-6 pb-10">
                        @if ($application->build_pack === 'dockercompose')
                            <div class="flex flex-col gap-2" x-init="$wire.dispatch('loadCompose', true)">
                                <div class="flex gap-2">
                                    <x-forms.input x-bind:disabled="initLoadingCompose" placeholder="/"
                                        id="application.base_directory" label="Base Directory"
                                        helper="Directory to use as root. Useful for monorepos." />
                                    <x-forms.input x-bind:disabled="initLoadingCompose"
                                        placeholder="/docker-compose.yaml" id="application.docker_compose_location"
                                        label="Docker Compose Location"
                                        helper="It is calculated together with the Base Directory:<br><span class='dark:text-warning'>{{ Str::start($application->base_directory . $application->docker_compose_location, '/') }}</span>" />
                                </div>
                                <div class="w-96">
                                    <x-forms.checkbox instantSave
                                        id="application.settings.is_preserve_repository_enabled"
                                        label="Preserve Repository During Deployment"
                                        helper="Git repository (based on the base directory settings) will be copied to the deployment directory." />
                                </div>
                                <div class="pt-4">The following commands are for advanced use cases.
                                    Only
                                    modify them if you
                                    know what are
                                    you doing.</div>
                                <div class="flex gap-2">
                                    <x-forms.input placeholder="docker compose build"
                                        x-bind:disabled="initLoadingCompose"
                                        id="application.docker_compose_custom_build_command"
                                        helper="If you use this, you need to specify paths relatively and should use the same compose file in the custom command, otherwise the automatically configured labels / etc won't work.<br><br>So in your case, use: <span class='dark:text-warning'>docker compose -f .{{ Str::start($application->base_directory . $application->docker_compose_location, '/') }} build</span>"
                                        label="Custom Build Command" />
                                    <x-forms.input placeholder="docker compose up -d"
                                        x-bind:disabled="initLoadingCompose"
                                        id="application.docker_compose_custom_start_command"
                                        helper="If you use this, you need to specify paths relatively and should use the same compose file in the custom command, otherwise the automatically configured labels / etc won't work.<br><br>So in your case, use: <span class='dark:text-warning'>docker compose -f .{{ Str::start($application->base_directory . $application->docker_compose_location, '/') }} up -d</span>"
                                        label="Custom Start Command" />
                                </div>
                            </div>
                        @else
                            <div class="flex flex-col gap-2 xl:flex-row">
                                <x-forms.input placeholder="/" id="application.base_directory" label="Base Directory"
                                    helper="Directory to use as root. Useful for monorepos." />
                                @if ($application->build_pack === 'dockerfile' && !$application->dockerfile)
                                    <x-forms.input placeholder="/Dockerfile" id="application.dockerfile_location"
                                        label="Dockerfile Location"
                                        helper="It is calculated together with the Base Directory:<br><span class='dark:text-warning'>{{ Str::start($application->base_directory . $application->dockerfile_location, '/') }}</span>" />
                                @endif

                                @if ($application->build_pack === 'dockerfile')
                                    <x-forms.input id="application.dockerfile_target_build"
                                        label="Docker Build Stage Target"
                                        helper="Useful if you have multi-staged dockerfile." />
                                @endif
                                @if ($application->could_set_build_commands())
                                    @if ($application->settings->is_static)
                                        <x-forms.input placeholder="/dist" id="application.publish_directory"
                                            label="Publish Directory" required />
                                    @else
                                        <x-forms.input placeholder="/" id="application.publish_directory"
                                            label="Publish Directory" />
                                    @endif
                                @endif

                            </div>
                            @if ($this->application->is_github_based() && !$this->application->is_public_repository())
                                <div class="pb-4">
                                    <x-forms.textarea
                                        helper="Gitignore-style rules to filter Git based webhook deployments."
                                        placeholder="src/pages/**" id="application.watch_paths"
                                        label="Watch Paths" />
                                </div>
                            @endif
                            <x-forms.input
                                helper="You can add custom docker run options that will be used when your container is started.<br>Note: Not all options are supported, as they could mess up Coolify's automation and could cause bad experience for users.<br><br>Check the <a class='underline dark:text-white' href='https://coolify.io/docs/knowledge-base/docker/custom-commands'>docs.</a>"
                                placeholder="--cap-add SYS_ADMIN --device=/dev/fuse --security-opt apparmor:unconfined --ulimit nofile=1024:1024 --tmpfs /run:rw,noexec,nosuid,size=65536k"
                                id="application.custom_docker_run_options" label="Custom Docker Options" />

                            @if ($application->build_pack !== 'dockercompose')
                                <div class="pt-2 w-96">
                                    <x-forms.checkbox
                                        helper="Use a build server to build your application. You can configure your build server in the Server settings. This is experimental. For more info, check the <a href='https://coolify.io/docs/knowledge-base/server/build-server' class='underline' target='_blank'>documentation</a>."
                                        instantSave id="application.settings.is_build_server_enabled"
                                        label="Use a Build Server? (experimental)" />
                                </div>
                            @endif
                            @if ($application->could_set_build_commands())
                                <div class="w-96">
                                    <x-forms.checkbox instantSave id="application.settings.is_static"
                                        label="Is it a static site?"
                                        helper="If your application is a static site or the final build assets should be served as a static site, enable this." />
                                </div>
                            @endif
                        @endif
                    </div>
                @endif
            </div>
            @if ($application->build_pack === 'dockercompose')
                <x-forms.button wire:target='initLoadingCompose'
                    x-on:click="$wire.dispatch('loadCompose', false)">Reload Compose File</x-forms.button>
                @if ($application->settings->is_raw_compose_deployment_enabled)
                    <x-forms.textarea rows="10" readonly id="application.docker_compose_raw"
                        label="Docker Compose Content (applicationId: {{ $application->id }})"
                        helper="You need to modify the docker compose file." monacoEditorLanguage="yaml"
                        useMonacoEditor />
                @else
                    @if ((int) $application->compose_parsing_version >= 3)
                        <x-forms.textarea rows="10" readonly id="application.docker_compose_raw"
                            label="Docker Compose Content (raw)" helper="You need to modify the docker compose file."
                            monacoEditorLanguage="yaml" useMonacoEditor />
                    @endif
                    <x-forms.textarea rows="10" readonly id="application.docker_compose"
                        label="Docker Compose Content" helper="You need to modify the docker compose file."
                        monacoEditorLanguage="yaml" useMonacoEditor />
                @endif
                <div class="w-96">
                    <x-forms.checkbox label="Escape special characters in labels?"
                        helper="By default, $ (and other chars) is escaped. So if you write $ in the labels, it will be saved as $$.<br><br>If you want to use env variables inside the labels, turn this off."
                        id="application.settings.is_container_label_escape_enabled" instantSave></x-forms.checkbox>
                    <x-forms.checkbox label="Readonly labels"
                        helper="If you know what are you doing, you can enable this to edit the labels directly. Coolify won't update labels automatically. <br><br>Be careful, it could break the proxy configuration after you restart the container."
                        id="application.settings.is_container_label_readonly_enabled" instantSave></x-forms.checkbox>
                </div>
            @endif
            @if ($application->dockerfile)
                <x-forms.textarea label="Dockerfile" id="application.dockerfile" monacoEditorLanguage="dockerfile"
                    useMonacoEditor rows="6"> </x-forms.textarea>
            @endif
            @if ($application->build_pack !== 'dockercompose')
                <h3 class="pt-8">Network</h3>
                <div class="flex flex-col gap-2 xl:flex-row">
                    @if ($application->settings->is_static || $application->build_pack === 'static')
                        <x-forms.input id="application.ports_exposes" label="Ports Exposes" readonly />
                    @else
                        <x-forms.input placeholder="3000,3001" id="application.ports_exposes" label="Ports Exposes"
                            required
                            helper="A comma separated list of ports your application uses. The first port will be used as default healthcheck port if nothing defined in the Healthcheck menu. Be sure to set this correctly." />
                    @endif
                    @if (!$application->destination->server->isSwarm())
                        <x-forms.input placeholder="3000:3000" id="application.ports_mappings" label="Ports Mappings"
                            helper="A comma separated list of ports you would like to map to the host system. Useful when you do not want to use domains.<br><br><span class='inline-block font-bold dark:text-warning'>Example:</span><br>3000:3000,3002:3002<br><br>Rolling update is not supported if you have a port mapped to the host." />
                    @endif
                </div>

                <x-forms.textarea label="Container Labels" rows="15" id="customLabels"
                    monacoEditorLanguage="ini" useMonacoEditor></x-forms.textarea>
                <div class="w-96">
                    <x-forms.checkbox label="Escape special characters in labels?"
                        helper="By default, $ (and other chars) is escaped. So if you write $ in the labels, it will be saved as $$.<br><br>If you want to use env variables inside the labels, turn this off."
                        id="application.settings.is_container_label_escape_enabled" instantSave></x-forms.checkbox>
                    <x-forms.checkbox label="Readonly labels"
                        helper="If you know what are you doing, you can enable this to edit the labels directly. Coolify won't update labels automatically. <br><br>Be careful, it could break the proxy configuration after you restart the container."
                        id="application.settings.is_container_label_readonly_enabled" instantSave></x-forms.checkbox>
                </div>
                <x-modal-confirmation title="Confirm Labels Reset to Coolify Defaults?"
                    buttonTitle="Reset Labels to Coolify Defaults" buttonFullWidth submitAction="resetDefaultLabels"
                    :actions="[
                        'All your custom proxy labels will be lost.',
                        'Proxy labels (traefik, caddy, etc) will be reset to the coolify defaults.',
                    ]" confirmationText="{{ $application->fqdn . '/' }}"
                    confirmationLabel="Please confirm the execution of the actions by entering the Application URL below"
                    shortConfirmationLabel="Application URL" :confirmWithPassword="false"
                    step2ButtonText="Permanently Reset Labels" />
            @endif

            <h3 class="pt-8">Pre/Post Deployment Commands</h3>
            <div class="flex flex-col gap-2 xl:flex-row">
                <x-forms.input x-bind:disabled="initLoadingCompose" placeholder="php artisan migrate"
                    id="application.pre_deployment_command" label="Pre-deployment "
                    helper="An optional script or command to execute in the existing container before the deployment begins.<br>It is always executed with 'sh -c', so you do not need add it manually." />
                @if ($application->build_pack === 'dockercompose')
                    <x-forms.input x-bind:disabled="initLoadingCompose"
                        id="application.pre_deployment_command_container" label="Container Name"
                        helper="The name of the container to execute within. You can leave it blank if your application only has one container." />
                @endif
            </div>
            <div class="flex flex-col gap-2 xl:flex-row">
                <x-forms.input x-bind:disabled="initLoadingCompose" placeholder="php artisan migrate"
                    id="application.post_deployment_command" label="Post-deployment "
                    helper="An optional script or command to execute in the newly built container after the deployment completes.<br>It is always executed with 'sh -c', so you do not need add it manually." />
                @if ($application->build_pack === 'dockercompose')
                    <x-forms.input x-bind:disabled="initLoadingCompose"
                        id="application.post_deployment_command_container" label="Container Name"
                        helper="The name of the container to execute within. You can leave it blank if your application only has one container." />
                @endif
            </div>
        </div>
    </form>
    @script
        <script>
            $wire.$on('loadCompose', (isInit = true) => {
                $wire.initLoadingCompose = true;
                $wire.loadComposeFile(isInit);
            });
        </script>
    @endscript
</div>
