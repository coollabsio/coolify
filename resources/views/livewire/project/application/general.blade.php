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
            <div class="flex items-end gap-2">
                <x-forms.input placeholder="https://coolify.io" id="application.fqdn" label="Domains"
                    helper="You can specify one domain with path or more with comma. You can specify a port to bind the domain to.<br><br><span class='text-helper'>Example</span><br>- http://app.coolify.io, https://cloud.coolify.io/dashboard<br>- http://app.coolify.io/api/v3<br>- http://app.coolify.io:3000 -> app.coolify.io will point to port 3000 inside the container. " />
                <x-forms.button wire:click="getWildcardDomain">Generate Domain
                </x-forms.button>
            </div>
            @if (!$application->dockerfile)
                <div class="flex flex-col gap-2">
                    <div class="flex gap-2">
                        <x-forms.select id="application.build_pack" label="Build Pack" required>
                            <option value="nixpacks">Nixpacks</option>
                            <option value="dockerfile">Dockerfile</option>
                        </x-forms.select>
                        @if ($application->settings->is_static)
                            <x-forms.select id="application.static_image" label="Static Image" required>
                                <option value="nginx:alpine">nginx:alpine</option>
                                <option disabled value="apache:alpine">apache:alpine</option>
                            </x-forms.select>
                        @endif
                    </div>
                    @if ($application->could_set_build_commands())
                        <div class="w-64">
                            <x-forms.checkbox instantSave id="is_static" label="Is it a static site?"
                                helper="If your application is a static site or the final build assets should be served as a static site, enable this." />
                        </div>
                    @endif
                </div>
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
                        helper="Directory to use as root. Useful for monorepos." />
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

            <h3>Network</h3>
            <div class="flex flex-col gap-2 xl:flex-row">
                @if ($application->settings->is_static)
                    <x-forms.input id="application.ports_exposes" label="Ports Exposes" readonly />
                @else
                    <x-forms.input placeholder="3000,3001" id="application.ports_exposes" label="Ports Exposes" required
                        helper="A comma separated list of ports your application uses. The first port will be used as default healthcheck port if nothing defined in the Healthcheck menu. Be sure to set this correctly." />
                @endif
                <x-forms.input placeholder="3000:3000" id="application.ports_mappings" label="Ports Mappings"
                    helper="A comma separated list of ports you would like to map to the host system. Useful when you do not want to use domains.<br><br><span class='inline-block font-bold text-warning'>Example:</span><br>3000:3000,3002:3002<br><br>Rolling update is not supported if you have a port mapped to the host." />
            </div>
        </div>
        <h3>Advanced</h3>
        <div class="flex flex-col">

            <x-forms.checkbox
                helper="Your application will be available only on https if your domain starts with https://..."
                instantSave id="is_force_https_enabled" label="Force Https" />
            @if ($application->git_based())
                <x-forms.checkbox helper="Automatically deploy new commits based on Git webhooks." instantSave
                    id="is_auto_deploy_enabled" label="Auto Deploy" />
                <x-forms.checkbox
                    helper="Allow to automatically deploy Preview Deployments for all opened PR's.<br><br>Closing a PR will delete Preview Deployments."
                    instantSave id="is_preview_deployments_enabled" label="Preview Deployments" />

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
