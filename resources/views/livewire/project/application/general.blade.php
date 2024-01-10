<div>
    <form wire:submit='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>General</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            @if ($isConfigurationChanged && !is_null($application->config_hash))
                <div class="font-bold text-warning">Configuration not applied to the running application. You need to
                    redeploy.</div>
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
                        <x-forms.checkbox instantSave id="application.settings.is_raw_compose_deployment_enabled"
                            label="Raw Compose Deployment"
                            helper="WARNING: Advanced use cases only. Your docker compose file will be deployed as-is. Nothing is modified by Coolify. You need to configure the proxy parts. More info in the <a href='https://coolify.io/docs/docker-compose'>documentation.</a>" />
                        @if (count($parsedServices) > 0 && !$application->settings->is_raw_compose_deployment_enabled)
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
                <h3>Docker Registry</h3>
                @if ($application->destination->server->isSwarm())
                    @if ($application->build_pack !== 'dockerimage')
                        <div>Docker Swarm requires the image to be available in a registry. More info <a
                                class="underline" href="https://coolify.io/docs/docker-registries"
                                target="_blank">here</a>.</div>
                    @endif
                @else
                    @if ($application->build_pack !== 'dockerimage')
                        <div>Push the built image to a docker registry. More info <a class="underline"
                                href="https://coolify.io/docs/docker-registries" target="_blank">here</a>.</div>
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
                        @if ($application->destination->server->isSwarm())
                            <x-forms.input id="application.docker_registry_image_name" required label="Docker Image" />
                            <x-forms.input id="application.docker_registry_image_tag"
                                helper="If set, it will tag the built image with this tag too. <br><br>Example: If you set it to 'latest', it will push the image with the commit sha tag + with the latest tag."
                                label="Docker Image Tag" />
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
                <h3>Build</h3>
                @if ($application->could_set_build_commands())
                    @if ($application->build_pack === 'nixpacks')
                        <div>Nixpacks will detect the required configuration automatically.
                            <a class="underline" href="https://coolify.io/docs/frameworks/">Framework Specific Docs</a>
                        </div>
                        <div class="flex flex-col gap-2 xl:flex-row">
                            <x-forms.input placeholder="If you modify this, you probably need to have a nixpacks.toml"
                                id="application.install_command" label="Install Command" />
                            <x-forms.input placeholder="If you modify this, you probably need to have a nixpacks.toml"
                                id="application.build_command" label="Build Command" />
                            <x-forms.input placeholder="If you modify this, you probably need to have a nixpacks.toml"
                                id="application.start_command" label="Start Command" />
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
                                helper="It is calculated together with the Base Directory:<br><span class='text-warning'>{{ Str::start($application->base_directory . $application->docker_compose_location, '/') }}</span>" />
                        </div>
                        <div class="pt-4">The following commands are for advanced use cases. Only modify them if you
                            know what are
                            you doing.</div>
                        <div class="flex gap-2">
                            <x-forms.input placeholder="docker compose build"
                                id="application.docker_compose_custom_build_command"
                                helper="If you use this, you need to specify paths relatively and should use the same compose file in the custom command, otherwise the automatically configured labels / etc won't work.<br><br>So in your case, use: <span class='text-warning'>docker compose -f .{{ Str::start($application->base_directory . $application->docker_compose_location, '/') }} build</span>"
                                label="Custom Build Command" />
                            <x-forms.input placeholder="docker compose up -d"
                                id="application.docker_compose_custom_start_command"
                                helper="If you use this, you need to specify paths relatively and should use the same compose file in the custom command, otherwise the automatically configured labels / etc won't work.<br><br>So in your case, use: <span class='text-warning'>docker compose -f .{{ Str::start($application->base_directory . $application->docker_compose_location, '/') }} up -d</span>"
                                label="Custom Start Command" />
                            {{-- <x-forms.input placeholder="/docker-compose.yaml" id="application.docker_compose_pr_location"
                    label="Docker Compose Location For Pull Requests"
                    helper="It is calculated together with the Base Directory:<br><span class='text-warning'>{{ Str::start($application->base_directory . $application->docker_compose_pr_location, '/') }}</span>" /> --}}
                        </div>
                    </div>
                @else
                    <div class="flex flex-col gap-2 xl:flex-row">
                        <x-forms.input placeholder="/" id="application.base_directory" label="Base Directory"
                            helper="Directory to use as root. Useful for monorepos." />
                        @if ($application->build_pack === 'dockerfile' && !$application->dockerfile)
                            <x-forms.input placeholder="/Dockerfile" id="application.dockerfile_location"
                                label="Dockerfile Location"
                                helper="It is calculated together with the Base Directory:<br><span class='text-warning'>{{ Str::start($application->base_directory . $application->dockerfile_location, '/') }}</span>" />
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
                @endif

            @endif
            @if ($application->build_pack === 'dockercompose')
                <x-forms.button wire:click="loadComposeFile">Reload Compose File</x-forms.button>
                @if ($application->settings->is_raw_compose_deployment_enabled)
                    <x-forms.textarea rows="10" readonly id="application.docker_compose_raw"
                        label="Docker Compose Content (applicationId: {{$application->id}})" helper="You need to modify the docker compose file." />
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
                <h3>Network</h3>
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
                            helper="A comma separated list of ports you would like to map to the host system. Useful when you do not want to use domains.<br><br><span class='inline-block font-bold text-warning'>Example:</span><br>3000:3000,3002:3002<br><br>Rolling update is not supported if you have a port mapped to the host." />
                    @endif
                </div>
                <x-forms.textarea label="Container Labels" rows="15" id="customLabels"></x-forms.textarea>
                <x-forms.button wire:click="resetDefaultLabels">Reset to Coolify Generated Labels</x-forms.button>
            @endif
        </div>
    </form>
</div>
