<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex gap-2">
            <h2>General</h2>
            <x-inputs.button type="submit">
                Save
            </x-inputs.button>
        </div>
        <x-inputs.checkbox instantSave id="is_static" label="Static website?" />
        <div class="flex flex-col gap-2 pb-4">
            <div class="flex flex-col items-end gap-2 xl:flex-row">
                <x-inputs.input class="w-full" id="application.name" label="Name" required />
                <x-inputs.input placeholder="https://coolify.io" class="w-full" id="application.fqdn" label="Domains"
                    helper="You can specify one domain with path or more with comma.<br><span class='inline-block font-bold text-warning'>Example</span>- http://app.coolify.io, https://cloud.coolify.io/dashboard<br>- http://app.coolify.io/api/v3" />

            </div>
            @if ($wildcard_domain)
                <div class="pb-6">
                    <div class="text-sm">Set Random Domain</div>
                    @if ($global_wildcard_domain)
                        <x-inputs.button isHighlighted wire:click="generateGlobalRandomDomain">Global Wildcard
                        </x-inputs.button>
                    @endif
                    @if ($project_wildcard_domain)
                        <x-inputs.button isHighlighted wire:click="generateProjectRandomDomain">Project Wildcard
                        </x-inputs.button>
                    @endif
                </div>
            @endif
            <x-inputs.select id="application.build_pack" label="Build Pack" required>
                <option value="nixpacks">Nixpacks</option>
                <option disabled value="docker">Docker</option>
                <option disabled value="compose">Compose</option>
            </x-inputs.select>
            @if ($application->settings->is_static)
                <x-inputs.select id="application.static_image" label="Static Image" required>
                    <option value="nginx:alpine">nginx:alpine</option>
                    <option disabled value="apache:alpine">apache:alpine</option>
                </x-inputs.select>
            @endif
            <div class="flex flex-col gap-2 pb-6 xl:flex-row">
                <x-inputs.input placeholder="pnpm install" id="application.install_command" label="Install Command" />
                <x-inputs.input placeholder="pnpm build" id="application.build_command" label="Build Command" />
                <x-inputs.input placeholder="pnpm start" id="application.start_command" label="Start Command" />
            </div>
            <div class="flex flex-col gap-2 xl:flex-row">
                <x-inputs.input placeholder="/" id="application.base_directory" label="Base Directory"
                    helper="Directory to use as root. Useful for monorepos." />
                @if ($application->settings->is_static)
                    <x-inputs.input placeholder="/dist" id="application.publish_directory" label="Publish Directory"
                        required />
                @else
                    <x-inputs.input placeholder="/" id="application.publish_directory" label="Publish Directory" />
                @endif
            </div>
            <div class="flex flex-col gap-2 xl:flex-row">
                @if ($application->settings->is_static)
                    <x-inputs.input id="application.ports_exposes" label="Ports Exposes" readonly />
                @else
                    <x-inputs.input placeholder="3000,3001" id="application.ports_exposes" label="Ports Exposes"
                        required helper="A comma separated list of ports you would like to expose for the proxy." />
                @endif
                <x-inputs.input placeholder="3000:3000" id="application.ports_mappings" label="Ports Mappings"
                    helper="A comma separated list of ports you would like to map to the host system. Useful when you do not want to use domains.<br><span class='inline-block font-bold text-warning'>Example</span>3000:3000,3002:3002" />
            </div>
        </div>
        <h3>Advanced</h3>
        <div class="flex flex-col">
            <x-inputs.checkbox helper="More logs will be visible during a deployment." instantSave id="is_debug"
                label="Debug" />
            <x-inputs.checkbox
                helper="Your application will be available only on https if your domain starts with https://..."
                instantSave id="is_force_https" label="Force Https" />
            <x-inputs.checkbox helper="Automatically deploy new commits based on Git webhooks." instantSave
                id="is_auto_deploy" label="Auto Deploy?" />
            {{-- <x-inputs.checkbox helper="Preview deployments" instantSave id="is_previews" label="Previews?" /> --}}
            <x-inputs.checkbox instantSave id="is_git_submodules_allowed" label="Git Submodules Allowed?" />
            <x-inputs.checkbox instantSave id="is_git_lfs_allowed" label="Git LFS Allowed?" />
            {{-- <x-inputs.checkbox disabled instantSave id="is_dual_cert" label="Dual Certs?" />
            <x-inputs.checkbox disabled instantSave id="is_custom_ssl" label="Is Custom SSL?" />
            <x-inputs.checkbox disabled instantSave id="is_http2" label="Is Http2?" /> --}}
        </div>
    </form>
</div>
