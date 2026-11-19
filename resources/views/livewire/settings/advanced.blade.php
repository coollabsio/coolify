<div>
    <x-slot:title>
        Advanced Settings | Coolify
    </x-slot>

    <x-settings.layout>
        <form wire:submit="submit" class="application-settings-form flex min-w-0 flex-col gap-6">
            {{-- Scope dirty tracking to fields that need an explicit Save. Instant-save
                 listboxes (API, MCP, telemetry, …) update the snapshot on the server
                 immediately; without wire:target they briefly flash this bar. --}}
            <x-unsaved-bar action="submit"
                targets="custom_dns_servers,allowed_ips,webhook_allowed_internal_hosts,webhook_allow_localhost,domain_connect_private_key,image_cdn_url" />

            <x-application.settings-section id="access-section" title="Access and API">
                <div class="grid gap-4 lg:grid-cols-2">
                     <x-forms.listbox id="is_registration_enabled" label="Registration"
                        helper="Allow users to create their own account. When disabled, only administrators can create accounts."
                        onChange="instantSave" :options="[
                            ['value' => true, 'label' => 'Anyone can register'],
                            ['value' => false, 'label' => 'Registration disabled'],
                         ]" />
                     <x-forms.listbox canGate="update" :canResource="$settings"
                         id="disable_registration_when_oauth_enabled" label="Password registration with OAuth"
                         helper="Hide password registration whenever at least one OAuth provider is enabled."
                         onChange="instantSave" :options="[
                             ['value' => false, 'label' => 'Allow password registration'],
                             ['value' => true, 'label' => 'Disable when OAuth is enabled'],
                         ]" />
                    <x-forms.listbox id="disable_two_step_confirmation" label="Destructive action confirmation"
                        helper="Choose whether destructive actions require password and text confirmation."
                        onChange="instantSave" :options="[
                            ['value' => false, 'label' => 'Require two-step confirmation'],
                            ['value' => true, 'label' => 'Skip two-step confirmation'],
                        ]" />
                    <x-forms.listbox canGate="update" :canResource="$settings" id="is_passkey_enabled" label="Passkeys"
                        helper="Let users sign in with a passkey. Passkeys need an HTTPS instance URL. Stored passkeys are kept when this is disabled."
                        onChange="instantSave" :options="[
                            ['value' => true, 'label' => 'Enabled'],
                            ['value' => false, 'label' => 'Disabled'],
                        ]" />
                </div>
                <x-application.settings-subsection id="api-section" title="API and MCP">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.listbox id="is_api_enabled" label="API access"
                            helper="Allow authenticated requests to the Coolify REST API." onChange="instantSave"
                            :options="[
                                ['value' => true, 'label' => 'Enabled'],
                                ['value' => false, 'label' => 'Disabled'],
                            ]" />
                        <x-forms.listbox id="is_mcp_server_enabled" label="MCP server"
                            helper="Expose the authenticated Streamable HTTP endpoint at /mcp." onChange="instantSave"
                            :options="[
                                ['value' => true, 'label' => 'Enabled'],
                                ['value' => false, 'label' => 'Disabled'],
                            ]" />
                        <div class="lg:col-span-2">
                            <x-forms.input id="allowed_ips" label="Allowed IPs"
                                helper="Applies to the REST API and the MCP endpoint. Comma-separated IPs or CIDR ranges. Empty or 0.0.0.0 allows all sources."
                                placeholder="192.168.1.100, 10.0.0.0/8" />
                        </div>
                    </div>
                    @if ($is_api_enabled && (empty($allowed_ips) || in_array('0.0.0.0', array_map('trim', explode(',', $allowed_ips ?? '')))))
                        <x-callout type="warning" title="API access is open to every source" class="mt-4">
                            Restrict the allowlist before using API access on a public production instance.
                        </x-callout>
                    @endif
                    @if ($is_mcp_server_enabled)
                        <x-callout type="info" title="MCP endpoint" class="mt-4">
                            <div class="flex items-center gap-1">
                                <code>{{ url('/mcp') }}</code>
                                <x-copy-button :value="url('/mcp')" label="Copy MCP endpoint" />
                            </div>
                            Authenticate with a bearer token from
                            <a href="{{ route('security.api-tokens') }}" class="font-medium underline" {{ wireNavigate() }}>Keys & Tokens → API Tokens</a>.
                        </x-callout>
                    @endif
                </x-application.settings-subsection>
            </x-application.settings-section>

            <x-application.settings-section id="network-section" title="Network"
                helper="Domain validation and the internal targets that outbound requests may reach.">
                <x-application.settings-subsection first id="dns-section" title="DNS validation">
                    <div class="grid gap-4 lg:grid-cols-2">
                        <x-forms.listbox id="is_dns_validation_enabled" label="DNS validation"
                            helper="Validate custom domains before deployment." onChange="instantSave" :options="[
                                ['value' => true, 'label' => 'Enabled'],
                                ['value' => false, 'label' => 'Disabled'],
                            ]" />
                        <x-forms.input id="custom_dns_servers" label="Custom DNS servers"
                            helper="Comma-separated resolvers. Leave empty to use system defaults."
                            placeholder="1.1.1.1, 8.8.8.8" />
                    </div>
                </x-application.settings-subsection>
                @if (isCloud())
                    <x-application.settings-subsection id="domain-connect-section" title="Domain Connect"
                        helper="Optional RSA private key used to sign Cloudflare Domain Connect apply URLs on Coolify Cloud. Leave blank to keep the existing key.">
                        <div class="grid gap-4">
                            <x-forms.input id="domain_connect_private_key" type="password" allowToPeak
                                label="Domain Connect private key (PEM)"
                                helper="Paste a PEM private key to set or rotate. Public key must be published at domainconnect.coolify.io. Env DOMAIN_CONNECT_PRIVATE_KEY is used as a fallback when this is empty."
                                placeholder="-----BEGIN PRIVATE KEY-----" />
                            @if (filled(data_get($settings, 'domain_connect_private_key')))
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-status-badge status="Key configured" type="success" />
                                    <x-forms.button type="button" wire:click="clearDomainConnectPrivateKey" isError>
                                        Remove key
                                    </x-forms.button>
                                </div>
                            @elseif (filled(config('services.domain_connect.private_key')))
                                <x-status-badge status="Using DOMAIN_CONNECT_PRIVATE_KEY from environment" type="neutral" />
                            @else
                                <x-callout type="info" title="Not configured">
                                    Automated Cloudflare DNS (Domain Connect) stays hidden until a private key is set.
                                </x-callout>
                            @endif
                        </div>
                    </x-application.settings-subsection>
                    <x-application.settings-subsection id="server-validation-section" title="Server validation"
                        helper="Limits failed SSH validations, so users cannot make Coolify Cloud try logins on hosts that they do not own. Successful connections do not count.">
                        <div class="grid gap-4 lg:grid-cols-3">
                            <x-forms.input id="server_validation_rate_limit_seconds" type="number" min="60" max="86400"
                                label="Rate limit window (seconds)"
                                helper="The period in which Coolify counts failed validations. From 60 to 86400 seconds." />
                            <x-forms.input id="server_validation_max_failures_per_ip" type="number" min="1" max="1000"
                                label="Max failed validations per IP"
                                helper="For one target IP, from all teams together, in the window. From 1 to 1000." />
                            <x-forms.input id="server_validation_max_failures_per_team" type="number" min="1" max="10000"
                                label="Max failed validations per team"
                                helper="For one team, to all IPs together, in the window. From 1 to 10000." />
                        </div>
                    </x-application.settings-subsection>
                @endif
                <x-application.settings-subsection id="endpoint-section" title="Outbound endpoints">
                    <div class="flex flex-col gap-4">
                        <x-forms.textarea id="webhook_allowed_internal_hosts" rows="4"
                            label="Allowed internal targets"
                            helper="Hostnames, IPs, or CIDR ranges separated by commas or new lines."
                            placeholder="hooks.company.local, 10.50.0.0/16" />
                        <div class="max-w-md">
                            <x-forms.listbox id="webhook_allow_localhost" label="Localhost targets"
                                helper="Loopback targets must also be present in the allowlist." :options="[
                                    ['value' => true, 'label' => 'Allowed'],
                                    ['value' => false, 'label' => 'Blocked'],
                                ]" />
                        </div>
                    </div>
                </x-application.settings-subsection>
            </x-application.settings-section>

            <x-application.settings-section id="interface-section" title="Interface and telemetry">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.listbox id="is_wire_navigate_enabled" label="Navigation"
                        helper="Prefetch pages and navigate without full reloads." onChange="instantSave" :options="[
                            ['value' => true, 'label' => 'SPA navigation'],
                            ['value' => false, 'label' => 'Full page navigation'],
                        ]" />
                    <x-forms.listbox id="do_not_track" label="Anonymous telemetry"
                        helper="Control installation counting and error reports." onChange="instantSave" :options="[
                            ['value' => false, 'label' => 'Enabled'],
                            ['value' => true, 'label' => 'Disabled'],
                        ]" />
                    <div class="flex flex-col gap-2">
                        <x-forms.listbox id="is_sponsorship_popup_enabled" label="Sponsorship reminders"
                            helper="Show the monthly project sponsorship reminder." onChange="instantSave" :options="[
                                ['value' => true, 'label' => 'Enabled'],
                                ['value' => false, 'label' => 'Disabled'],
                            ]" />
                        @if (isDev())
                            <x-forms.button type="button" @click="$dispatch('show-sponsorship-reminder')">
                                Show sponsorship reminder
                            </x-forms.button>
                        @endif
                    </div>
                </div>
            </x-application.settings-section>

            <x-application.settings-section id="storage-section" title="Storage"
                helper="Where Coolify stores uploaded images and how users download files.">
                <x-application.settings-subsection first id="avatar-storage-section" title="Image storage"
                    helper="Choose where compressed profile pictures and project icons are stored. Use S3 for multi-instance or cloud deployments so every application replica can access the same files.">
                    <div class="flex max-w-md flex-col gap-4">
                        <x-forms.listbox id="avatar_storage" label="Storage destination" onChange="instantSave"
                            :options="$avatar_storage_options" />
                        <x-forms.input id="image_cdn_url" label="Image CDN URL"
                            helper="Optional public URL (for example a CDN or public bucket domain) that serves profile pictures and project icons stored on S3. Leave empty to serve them through Coolify." placeholder="https://images.example.com" />
                    </div>
                    @if (count($avatar_storage_options) === 1)
                        <x-callout type="info" title="No usable S3 storage configured" class="mt-4">
                            Add and test an S3-compatible storage under Storages before selecting it here.
                        </x-callout>
                    @endif
                </x-application.settings-subsection>
                <x-application.settings-subsection id="file-download-storage-section" title="File downloads"
                    helper="Choose how files from the container file browser and backups are downloaded. Streaming sends each file through this Coolify instance (file browser: up to 1 GB). With S3, the server uploads the file to the bucket and the browser downloads it from S3, up to 5 GB; larger backups are streamed. S3 download links expire after 15 minutes, and the files are deleted after 6 hours.">
                    <div class="flex max-w-md flex-col gap-4">
                        <x-forms.listbox id="file_download_storage" label="Download method" onChange="instantSave"
                            :options="[
                                ['value' => 'stream', 'label' => 'Stream through Coolify'],
                                ...array_values(array_filter($avatar_storage_options, fn ($option) => $option['value'] !== 'local')),
                            ]" />
                    </div>
                    <x-callout type="info" title="Bucket auto-delete rule" class="mt-4">
                        When you select an S3 storage, Coolify adds a lifecycle rule that deletes objects under
                        <span class="font-mono">coolify-file-downloads/</span> after 1 day, as a safety net. The selected
                        storage must be reachable from your servers, and the S3 provider must support lifecycle rules.
                    </x-callout>
                </x-application.settings-subsection>
            </x-application.settings-section>

        </form>
    </x-settings.layout>
</div>
