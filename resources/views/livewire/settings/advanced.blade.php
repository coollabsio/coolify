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
                targets="custom_dns_servers,allowed_ips,webhook_allowed_internal_hosts,webhook_allow_localhost,domain_connect_private_key" />

            <x-application.settings-section id="access-section" title="Access">
                <div class="grid gap-4 lg:grid-cols-2">
                    <x-forms.listbox id="is_registration_enabled" label="Registration"
                        helper="Allow users to create their own account. When disabled, only administrators can create accounts."
                        onChange="instantSave" :options="[
                            ['value' => true, 'label' => 'Anyone can register'],
                            ['value' => false, 'label' => 'Registration disabled'],
                        ]" />
                    <x-forms.listbox id="disable_two_step_confirmation" label="Destructive action confirmation"
                        helper="Choose whether destructive actions require password and text confirmation."
                        onChange="instantSave" :options="[
                            ['value' => false, 'label' => 'Require two-step confirmation'],
                            ['value' => true, 'label' => 'Skip two-step confirmation'],
                        ]" />
                </div>
            </x-application.settings-section>

            <x-application.settings-section id="dns-section" title="DNS validation">
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
            </x-application.settings-section>

            @if (isCloud())
                <x-application.settings-section id="domain-connect-section" title="Domain Connect"
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
                </x-application.settings-section>
            @endif

            <x-application.settings-section id="api-section" title="API and MCP">
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
                        <x-forms.input id="allowed_ips" label="Allowed API IPs"
                            helper="Comma-separated IPs or CIDR ranges. Empty or 0.0.0.0 allows all sources."
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
                        <code>{{ url('/mcp') }}</code> uses Sanctum bearer tokens from Security → API Tokens.
                    </x-callout>
                @endif
            </x-application.settings-section>

            <x-application.settings-section id="endpoint-section" title="Outbound endpoints">
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
                    <x-forms.listbox id="is_sponsorship_popup_enabled" label="Sponsorship reminders"
                        helper="Show the monthly project sponsorship reminder." onChange="instantSave" :options="[
                            ['value' => true, 'label' => 'Enabled'],
                            ['value' => false, 'label' => 'Disabled'],
                        ]" />
                </div>
            </x-application.settings-section>

            <x-application.settings-section id="avatar-storage-section" title="Image storage"
                helper="Choose where compressed profile pictures and project icons are stored. Use S3 for multi-instance or cloud deployments so every application replica can access the same files.">
                <div class="max-w-md">
                    <x-forms.listbox id="avatar_storage" label="Storage destination" onChange="instantSave"
                        :options="$avatar_storage_options" />
                </div>
                @if (count($avatar_storage_options) === 1)
                    <x-callout type="info" title="No usable S3 storage configured" class="mt-4">
                        Add and test an S3-compatible storage under Storages before selecting it here.
                    </x-callout>
                @endif
            </x-application.settings-section>
        </form>
    </x-settings.layout>
</div>
