<form wire:submit="submit" class="application-settings-form flex w-full min-w-0 flex-col gap-6">
    <x-application.settings-section title="Connect3 staging"
        description="Every project with a client slug gets &lt;slug&gt;.&lt;apex&gt; behind basic auth and noindex. Point A &lt;apex&gt; and A *.&lt;apex&gt; at this server (DNS-only / grey cloud). With a Cloudflare token scoped to Zone:DNS:Edit on that zone, Traefik issues one wildcard certificate via DNS-01 instead of one HTTP-01 certificate per hostname.">
        <div class="grid gap-4 lg:grid-cols-2">
            <x-forms.input canGate="update" :canResource="$settings" id="c3_staging_apex" label="Staging apex"
                placeholder="sites.example-staging.com"
                helper="Bare domain, no scheme. Recommended: a dedicated domain separate from your brand domain." />
            <x-forms.input canGate="update" :canResource="$settings" id="c3_cloudflare_dns_token" type="password"
                label="Cloudflare DNS API token"
                helper="Optional. Enables the c3wildcard DNS-01 resolver for *.&lt;apex&gt;. Stored encrypted; written root-only to the proxy directory, never into docker-compose." />
        </div>
        <div class="flex items-center gap-2">
            <x-forms.button type="submit" canGate="update" :canResource="$settings">Save</x-forms.button>
            <span class="text-xs opacity-70">Changing the token requires a proxy restart.</span>
        </div>
    </x-application.settings-section>
</form>
