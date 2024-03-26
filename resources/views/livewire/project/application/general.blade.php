<div>
    <form wire:submit='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>General</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            @if ($isConfigurationChanged && !is_null($application->config_hash) && !$application->isExited())
                <div title="Configuration not applied to the running application. You need to redeploy.">
                    <svg class="w-6 h-6 dark:text-warning" viewBox="0 0 256 256" xmlns="http://www.w3.org/2000/svg">
                        <path fill="currentColor"
                            d="M240.26 186.1L152.81 34.23a28.74 28.74 0 0 0-49.62 0L15.74 186.1a27.45 27.45 0 0 0 0 27.71A28.31 28.31 0 0 0 40.55 228h174.9a28.31 28.31 0 0 0 24.79-14.19a27.45 27.45 0 0 0 .02-27.71m-20.8 15.7a4.46 4.46 0 0 1-4 2.2H40.55a4.46 4.46 0 0 1-4-2.2a3.56 3.56 0 0 1 0-3.73L124 46.2a4.77 4.77 0 0 1 8 0l87.44 151.87a3.56 3.56 0 0 1 .02 3.73M116 136v-32a12 12 0 0 1 24 0v32a12 12 0 0 1-24 0m28 40a16 16 0 1 1-16-16a16 16 0 0 1 16 16" />
                    </svg>
                </div>
            @endif
        </div>
        <div>General configuration for your application.</div>
        <div class="flex flex-col gap-2 py-4">
            <div class="flex flex-col items-end gap-2 xl:flex-row">
                <x-forms.input id="application.name" label="Name" required />
                <x-forms.input id="application.description" label="Description" />
            </div>

            @if (!$application->dockerfile && $application->build_pack !== 'dockerimage')
                <div class="flex flex-col gap-2">
                    <div class="flex gap-2">
                        <x-forms.select wire:model.live="application.build_pack" label="Build Pack" required>
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
                    @if ($application->could_set_build_commands())
                        <div class="w-64">
                            <x-forms.checkbox instantSave id="application.settings.is_static"
                                label="Is it a static site?"
                                helper="If your application is a static site or the final build assets should be served as a static site, enable this." />
                        </div>
                    @endif
                    @if ($application->build_pack === 'dockercompose')
                        <div class="w-96">
                            <x-forms.checkbox instantSave id="application.settings.is_raw_compose_deployment_enabled"
                                label="Raw Compose Deployment"
                                helper="WARNING: Advanced use cases only. Your docker compose file will be deployed as-is. Nothing is modified by Coolify. You need to configure the proxy parts. More info in the <a href='https://coolify.io/docs/docker/compose#raw-docker-compose-deployment'>documentation.</a>" />
                        </div>
                        @if (count($parsedServices) > 0 && !$application->settings->is_raw_compose_deployment_enabled)
                            <h3>Domains</h3>
                            @foreach (data_get($parsedServices, 'services') as $serviceName => $service)
                                @if (!isDatabaseImage(data_get($service, 'image')))
                                    <div class="flex items-end gap-2">
                                        <x-forms.input
                                            helper="You can specify one domain with path or more with comma. You can specify a port to bind the domain to.<br><br><span class='text-helper'>Example</span><br>- http://app.coolify.io, https://cloud.coolify.io/dashboard<br>- http://app.coolify.io/api/v3<br>- http://app.coolify.io:3000 -> app.coolify.io will point to port 3000 inside the container. "
                                            label="Domains for {{ str($serviceName)->headline() }}"
                                            id="parsedServiceDomains.{{ $serviceName }}.domain"></x-forms.input>
                                        @if (!data_get($parsedServiceDomains, "$serviceName.domain"))
                                            <x-forms.button wire:click="generateDomain('{{ $serviceName }}')">Generate
                                                Domain</x-forms.button>
                                        @endif
                                    </div>
                                @endif
                            @endforeach
                        @endif
                    @endif
                </div>
            @endif
            @if ($application->build_pack !== 'dockercompose')
                <div class="flex items-end gap-2">
                    <x-forms.input placeholder="https://coolify.io" id="application.fqdn" label="Domains"
                        helper="You can specify one domain with path or more with comma. You can specify a port to bind the domain to.<br><br><span class='text-helper'>Example</span><br>- http://app.coolify.io, https://cloud.coolify.io/dashboard<br>- http://app.coolify.io/api/v3<br>- http://app.coolify.io:3000 -> app.coolify.io will point to port 3000 inside the container. " />
                    <x-forms.button wire:click="getWildcardDomain">Generate Domain
                    </x-forms.button>
                </div>
            @endif

            @if ($application->build_pack !== 'dockercompose')
                <div class="flex items-center gap-2 pt-8">
                    <h3>Docker Registry</h3>
                    @if ($application->build_pack !== 'dockerimage' && !$application->destination->server->isSwarm())
                        <x-helper
                            helper="Push the built image to a docker registry. More info <a class='underline' href='https://coolify.io/docs/docker/registry' target='_blank'>here</a>." />
                    @endif
                </div>
                @if ($application->destination->server->isSwarm())
                    @if ($application->build_pack !== 'dockerimage')
                        <div>Docker Swarm requires the image to be available in a registry. More info <a
                                class="underline" href="https://coolify.io/docs/docker/registry"
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

            @if ($application->build_pack !== 'dockerimage')
                <h3 class="pt-8">Build</h3>
                @if ($application->build_pack !== 'dockercompose')
                    <div class="w-96">
                        <x-forms.checkbox
                            helper="Use a build server to build your application. You can configure your build server in the Server settings. This is experimental. For more info, check the <a href='https://coolify.io/docs/server/build-server' class='underline' target='_blank'>documentation</a>."
                            instantSave id="application.settings.is_build_server_enabled"
                            label="Use a Build Server? (experimental)" />
                    </div>
                @endif
                @if ($application->could_set_build_commands())
                    @if ($application->build_pack === 'nixpacks')
                        <div class="flex flex-col gap-2 xl:flex-row">
                            <x-forms.input placeholder="If you modify this, you probably need to have a nixpacks.toml"
                                id="application.install_command" label="Install Command" />
                            <x-forms.input placeholder="If you modify this, you probably need to have a nixpacks.toml"
                                id="application.build_command" label="Build Command" />
                            <x-forms.input placeholder="If you modify this, you probably need to have a nixpacks.toml"
                                id="application.start_command" label="Start Command" />
                        </div>
                        <div>Nixpacks will detect the required configuration automatically.
                            <a class="underline" href="https://coolify.io/docs/frameworks/">Framework Specific Docs</a>
                        </div>
                    @endif
                @endif
                @if ($application->build_pack === 'dockercompose')
                    <div class="flex flex-col gap-2" wire:init='loadComposeFile(true)'>
                        <div class="flex gap-2">
                            <x-forms.input placeholder="/" id="application.base_directory" label="Base Directory"
                                helper="Directory to use as root. Useful for monorepos." />
                            <x-forms.input placeholder="/docker-compose.yaml" id="application.docker_compose_location"
                                label="Docker Compose Location"
                                helper="It is calculated together with the Base Directory:<br><span class='dark:text-warning'>{{ Str::start($application->base_directory . $application->docker_compose_location, '/') }}</span>" />
                        </div>
                        <div class="pt-4">The following commands are for advanced use cases. Only modify them if you
                            know what are
                            you doing.</div>
                        <div class="flex gap-2">
                            <x-forms.input placeholder="docker compose build"
                                id="application.docker_compose_custom_build_command"
                                helper="If you use this, you need to specify paths relatively and should use the same compose file in the custom command, otherwise the automatically configured labels / etc won't work.<br><br>So in your case, use: <span class='dark:text-warning'>docker compose -f .{{ Str::start($application->base_directory . $application->docker_compose_location, '/') }} build</span>"
                                label="Custom Build Command" />
                            <x-forms.input placeholder="docker compose up -d"
                                id="application.docker_compose_custom_start_command"
                                helper="If you use this, you need to specify paths relatively and should use the same compose file in the custom command, otherwise the automatically configured labels / etc won't work.<br><br>So in your case, use: <span class='dark:text-warning'>docker compose -f .{{ Str::start($application->base_directory . $application->docker_compose_location, '/') }} up -d</span>"
                                label="Custom Start Command" />
                            {{-- <x-forms.input placeholder="/docker-compose.yaml" id="application.docker_compose_pr_location"
                    label="Docker Compose Location For Pull Requests"
                    helper="It is calculated together with the Base Directory:<br><span class='dark:text-warning'>{{ Str::start($application->base_directory . $application->docker_compose_pr_location, '/') }}</span>" /> --}}
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
                            <x-forms.input id="application.dockerfile_target_build" label="Docker Build Stage Target"
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
                    <div>The following options are for advanced use cases. Only modify them if you
                        know what are
                        you doing.</div>
                    <x-forms.input
                        helper="You can add custom docker run options that will be used when your container is started.<br>Note: Not all options are supported, as they could mess up Coolify's automation and could cause bad experience for users.<br><br>Check the <a class='underline dark:text-white' href='https://coolify.io/docs/custom-docker-options'>docs.</a>"
                        placeholder="--cap-add SYS_ADMIN --device=/dev/fuse --security-opt apparmor:unconfined --ulimit nofile=1024:1024 --tmpfs /run:rw,noexec,nosuid,size=65536k"
                        id="application.custom_docker_run_options" label="Custom Docker Options" />
                @endif
            @else
                <x-forms.input
                    helper="You can add custom docker run options that will be used when your container is started.<br>Note: Not all options are supported, as they could mess up Coolify's automation and could cause bad experience for users.<br><br>Check the <a class='underline dark:text-white' href='https://coolify.io/docs/custom-docker-options'>docs.</a>"
                    placeholder="--cap-add SYS_ADMIN --device=/dev/fuse --security-opt apparmor:unconfined --ulimit nofile=1024:1024 --tmpfs /run:rw,noexec,nosuid,size=65536k"
                    id="application.custom_docker_run_options" label="Custom Docker Options" />
            @endif
            @if ($application->build_pack === 'dockercompose')
                <x-forms.button wire:click="loadComposeFile">Reload Compose File</x-forms.button>
                @if ($application->settings->is_raw_compose_deployment_enabled)
                    <x-forms.textarea rows="10" readonly id="application.docker_compose_raw"
                        label="Docker Compose Content (applicationId: {{ $application->id }})"
                        helper="You need to modify the docker compose file." />
                @else
                    <x-forms.textarea rows="10" readonly id="application.docker_compose"
                        label="Docker Compose Content" helper="You need to modify the docker compose file." />
                @endif
                {{-- <x-forms.textarea rows="10" readonly id="application.docker_compose_pr"
                    label="Docker PR Compose Content" helper="You need to modify the docker compose file." /> --}}
            @endif

            @if ($application->dockerfile)
                <x-forms.textarea label="Dockerfile" id="application.dockerfile" rows="6"> </x-forms.textarea>
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
                <x-forms.textarea label="Container Labels" rows="15" id="customLabels"></x-forms.textarea>
                <x-forms.button wire:click="resetDefaultLabels">Reset to Coolify Generated Labels</x-forms.button>
            @endif

            <h3 class="pt-8">Pre/Post Deployment Commands</h3>
            <div class="flex flex-col gap-2 xl:flex-row">
                <x-forms.input id="application.pre_deployment_command" label="Pre-deployment Command"
                    helper="An optional script or command to execute in the existing container before the deployment begins." />
                <x-forms.input id="application.pre_deployment_command_container" label="Container Name"
                    helper="The name of the container to execute within. You can leave it blank if your application only has one container." />
            </div>
            <div class="flex flex-col gap-2 xl:flex-row">
                <x-forms.input placeholder="php artisan migrate" id="application.post_deployment_command"
                    label="Post-deployment Command"
                    helper="An optional script or command to execute in the newly built container after the deployment completes." />
                <x-forms.input id="application.post_deployment_command_container" label="Container Name"
                    helper="The name of the container to execute within. You can leave it blank if your application only has one container." />
            </div>
        </div>
    </form>
</div>
