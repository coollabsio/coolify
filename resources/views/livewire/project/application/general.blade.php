<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex items-center gap-2">
            <h2>General</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
        </div>
        <div class="">General configuration for your application.</div>
        <div class="flex flex-col gap-2 py-4">
            <div class="flex flex-col items-end gap-2 xl:flex-row">
                <x-forms.input id="application.name" label="Name" required />
                <x-forms.input id="application.description" label="Description" />
            </div>
            @if ($services->count() === 0)
                <div class="flex items-end gap-2">
                    <x-forms.input placeholder="https://coolify.io" id="application.fqdn" label="Domains"
                        helper="You can specify one domain with path or more with comma.<br><span class='text-helper'>Example</span>- http://app.coolify.io, https://cloud.coolify.io/dashboard<br>- http://app.coolify.io/api/v3" />
                    @if ($wildcard_domain)
                        @if ($global_wildcard_domain)
                            <x-forms.button wire:click="generateGlobalRandomDomain">Set Global Wildcard
                            </x-forms.button>
                        @endif
                        @if ($server_wildcard_domain)
                            <x-forms.button wire:click="generateServerRandomDomain">Set Server Wildcard
                            </x-forms.button>
                        @endif
                    @endif
                </div>
                <div class="flex items-end gap-2">
                    @if ($application->build_pack === 'dockerfile')
                        <x-forms.select disabled id="application.build_pack" label="Build Pack" required>
                            <option value="dockerfile">Dockerfile</option>
                        </x-forms.select>
                    @elseif ($application->build_pack === 'dockercompose')
                        <x-forms.select disabled id="application.build_pack" label="Build Pack" required>
                            <option selected value="dockercompose">Docker Compose</option>
                        </x-forms.select>
                    @else
                        <x-forms.select id="application.build_pack" label="Build Pack" required>
                            <option value="nixpacks">Nixpacks</option>
                            <option value="dockerfile">Dockerfile</option>
                            <option disabled value="dockercompose">Docker Compose</option>
                        </x-forms.select>
                    @endif

                </div>
            @endif
            @if ($application->settings->is_static)
                <x-forms.select id="application.static_image" label="Static Image" required>
                    <option value="nginx:alpine">nginx:alpine</option>
                    <option disabled value="apache:alpine">apache:alpine</option>
                </x-forms.select>
            @endif
            @if ($application->could_set_build_commands())
                <h3>Build</h3>
                <div class="flex flex-col gap-2 xl:flex-row">
                    <x-forms.input placeholder="pnpm install" id="application.install_command"
                        label="Install Command" />
                    <x-forms.input placeholder="pnpm build" id="application.build_command" label="Build Command" />
                    <x-forms.input placeholder="pnpm start" id="application.start_command" label="Start Command" />
                </div>
                <div class="flex flex-col gap-2 xl:flex-row">
                    <x-forms.input placeholder="/" id="application.base_directory" label="Base Directory"
                        helper="Directory to use as root. Useful for monorepos. WIP" disabled />
                    @if ($application->settings->is_static)
                        <x-forms.input placeholder="/dist" id="application.publish_directory" label="Publish Directory"
                            required />
                    @else
                        <x-forms.input placeholder="/" id="application.publish_directory" label="Publish Directory" />
                    @endif
                </div>
            @endif
            @if ($application->dockerfile)
                <x-forms.textarea label="Dockerfile" id="application.dockerfile" rows="6"> </x-forms.textarea>
            @endif
            @if ($services->count() > 0)
                <h3>Services</h3>
                @foreach ($services as $serviceName => $service)
                    @if (!data_get($service, 'is_database'))
                        <h4>{{ Str::headline($serviceName) }}</h4>
                        <div class="flex gap-2">
                            <x-forms.input id="service_configurations.{{ $serviceName }}.fqdn" label="FQDN">
                            </x-forms.input>
                            {{-- <x-forms.input id="service_configurations.{{ $serviceName }}.port"
                            label="{{ Str::headline($serviceName) }} Port">
                        </x-forms.input> --}}
                        </div>
                    @endif
                @endforeach
                <x-forms.textarea label="Docker Compose (Raw)" id="application.dockercompose_raw" rows="16">
                </x-forms.textarea>
                <x-forms.textarea readonly helper="Added all required fields" label="Docker Compose (Coolified)"
                    id="application.dockercompose" rows="6">
                </x-forms.textarea>
            @else
                <h3>Network</h3>
                <div class="flex flex-col gap-2 xl:flex-row">
                    @if ($application->settings->is_static)
                        <x-forms.input id="application.ports_exposes" label="Ports Exposes" readonly />
                    @else
                        <x-forms.input placeholder="3000,3001" id="application.ports_exposes" label="Ports Exposes"
                            required helper="A comma separated list of ports you would like to expose for the proxy." />
                    @endif
                    <x-forms.input placeholder="3000:3000" id="application.ports_mappings" label="Ports Mappings"
                        helper="A comma separated list of ports you would like to map to the host system. Useful when you do not want to use domains.<br><span class='inline-block font-bold text-warning'>Example</span>3000:3000,3002:3002" />
                </div>
            @endif
        </div>
        <h3>Advanced</h3>
        <div class="flex flex-col">
            @if ($application->could_set_build_commands())
                <x-forms.checkbox instantSave id="is_static" label="Is it a static site?"
                    helper="If your application is a static site or the final build assets should be served as a static site, enable this." />
            @endif
            <x-forms.checkbox
                helper="Your application will be available only on https if your domain starts with https://..."
                instantSave id="is_force_https_enabled" label="Force Https" />
            @if ($application->git_based())
                <x-forms.checkbox helper="Automatically deploy new commits based on Git webhooks." instantSave
                    id="is_auto_deploy_enabled" label="Auto Deploy" />
                <x-forms.checkbox
                    helper="Allow to automatically deploy Preview Deployments for all opened PR's.<br><br>Closing a PR will delete Preview Deployments."
                    instantSave id="is_preview_deployments_enabled" label="Previews Deployments" />

                <x-forms.checkbox instantSave id="is_git_submodules_enabled" label="Git Submodules"
                    helper="Allow Git Submodules during build process." />
                <x-forms.checkbox instantSave id="is_git_lfs_enabled" label="Git LFS"
                    helper="Allow Git LFS during build process." />
            @endif

            {{-- <x-forms.checkbox disabled instantSave id="is_dual_cert" label="Dual Certs?" />
            <x-forms.checkbox disabled instantSave id="is_custom_ssl" label="Is Custom SSL?" />
            <x-forms.checkbox disabled instantSave id="is_http2" label="Is Http2?" /> --}}
        </div>
    </form>
</div>
