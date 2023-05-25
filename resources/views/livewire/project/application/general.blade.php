<div>
    <form wire:submit.prevent='submit' class="flex flex-col">
        <div class="flex gap-2">
            <h2>General</h2>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
        </div>
        <x-forms.checkbox instantSave id="is_static" label="Static website?" />
        <div class="flex flex-col gap-2 pb-4">
            <div class="flex flex-col items-end gap-2 xl:flex-row">
                <x-forms.input class="w-full" id="application.name" label="Name" required />
                <x-forms.input placeholder="https://coolify.io" class="w-full" id="application.fqdn" label="Domains"
                    helper="You can specify one domain with path or more with comma.<br><span class='inline-block font-bold text-warning'>Example</span>- http://app.coolify.io, https://cloud.coolify.io/dashboard<br>- http://app.coolify.io/api/v3" />

            </div>
            @if ($wildcard_domain)
                <div class="pb-6">
                    <div class="text-sm">Set Random Domain</div>
                    @if ($global_wildcard_domain)
                        <x-forms.button isHighlighted wire:click="generateGlobalRandomDomain">Global Wildcard
                        </x-forms.button>
                    @endif
                    @if ($project_wildcard_domain)
                        <x-forms.button isHighlighted wire:click="generateProjectRandomDomain">Project Wildcard
                        </x-forms.button>
                    @endif
                </div>
            @endif
            <x-forms.select id="application.build_pack" label="Build Pack" required>
                <option value="nixpacks">Nixpacks</option>
                <option disabled value="docker">Docker</option>
                <option disabled value="compose">Compose</option>
            </x-forms.select>
            @if ($application->settings->is_static)
                <x-forms.select id="application.static_image" label="Static Image" required>
                    <option value="nginx:alpine">nginx:alpine</option>
                    <option disabled value="apache:alpine">apache:alpine</option>
                </x-forms.select>
            @endif
            <div class="flex flex-col gap-2 pb-6 xl:flex-row">
                <x-forms.input placeholder="pnpm install" id="application.install_command" label="Install Command" />
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
            <div class="flex flex-col gap-2 xl:flex-row">
                @if ($application->settings->is_static)
                    <x-forms.input id="application.ports_exposes" label="Ports Exposes" readonly />
                @else
                    <x-forms.input placeholder="3000,3001" id="application.ports_exposes" label="Ports Exposes" required
                        helper="A comma separated list of ports you would like to expose for the proxy." />
                @endif
                <x-forms.input placeholder="3000:3000" id="application.ports_mappings" label="Ports Mappings"
                    helper="A comma separated list of ports you would like to map to the host system. Useful when you do not want to use domains.<br><span class='inline-block font-bold text-warning'>Example</span>3000:3000,3002:3002" />
            </div>
        </div>
        <h3>Advanced</h3>
        <div class="flex flex-col">
            <x-forms.checkbox helper="More logs will be visible during a deployment." instantSave id="is_debug"
                label="Debug" />
            <x-forms.checkbox
                helper="Your application will be available only on https if your domain starts with https://..."
                instantSave id="is_force_https" label="Force Https" />
            <x-forms.checkbox helper="Automatically deploy new commits based on Git webhooks." instantSave
                id="is_auto_deploy" label="Auto Deploy?" />
            {{-- <x-forms.checkbox helper="Preview deployments" instantSave id="is_previews" label="Previews?" /> --}}
            <x-forms.checkbox instantSave id="is_git_submodules_allowed" label="Git Submodules Allowed?" />
            <x-forms.checkbox instantSave id="is_git_lfs_allowed" label="Git LFS Allowed?" />
            {{-- <x-forms.checkbox disabled instantSave id="is_dual_cert" label="Dual Certs?" />
            <x-forms.checkbox disabled instantSave id="is_custom_ssl" label="Is Custom SSL?" />
            <x-forms.checkbox disabled instantSave id="is_http2" label="Is Http2?" /> --}}
        </div>
    </form>
</div>
