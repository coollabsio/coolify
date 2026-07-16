{{-- OpenStack Keystone v3 application credential fields --}}
<x-forms.input required id="os_auth_url" label="Auth URL (Keystone)"
    placeholder="https://identity.example.cloud:443/v3" helper="The OS_AUTH_URL from your openrc file." />
<x-forms.input required id="os_application_credential_id" label="Application Credential ID"
    placeholder="Your OpenStack application credential ID" />
<x-forms.input required type="password" id="os_application_credential_secret"
    label="Application Credential Secret" placeholder="Enter the application credential secret" />
<x-forms.input id="os_region" label="Region (optional)"
    placeholder="e.g., RegionOne" helper="Only needed if your cloud exposes multiple regions." />

@if (auth()->user()->currentTeam()->cloudProviderTokens->where('provider', 'openstack')->isEmpty())
    <div class="text-sm text-neutral-500 dark:text-neutral-400">
        Create an application credential in the OpenStack dashboard (Horizon) under
        <span class="dark:text-white">Identity → Application Credentials</span>, or with the CLI:
        <code class="text-xs">openstack application credential create coolify</code>.
    </div>
@endif
