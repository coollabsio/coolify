<form wire:submit.prevent="save" class="flex flex-col w-full gap-4"
    x-data="{ domain: @js($domain) }">
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <x-forms.input canGate="update" :canResource="$server" id="name" :value="$name" label="Service Name" required
            placeholder="my-backend-api"
            helper="Used to derive the Traefik router and service names (e.g. gateway-my-backend-api)." />
        <x-forms.input canGate="update" :canResource="$server" id="target_url" :value="$target_url" label="Target URL"
            required placeholder="http://192.168.1.10:3000"
            helper="Where Traefik forwards matching traffic. Include scheme and port, e.g. http://10.0.0.5:8080." />
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <x-forms.input canGate="update" :canResource="$server" id="domain" :value="$domain" label="Domain" required
            placeholder="api.example.com"
            helper="Hostname Traefik matches against. Use *.example.com for wildcard subdomains."
            x-on:input="domain = $event.target.value" />
        <x-forms.input canGate="update" :canResource="$server" id="path_prefix" :value="$path_prefix"
            label="Path Prefix" placeholder="/"
            helper="Optional PathPrefix rule. Use / to match all paths on the domain." />
    </div>

    <div x-show="domain.startsWith('*.')" x-cloak>
        <x-callout type="warning" title="Wildcard domain">
            Wildcard certs need a <strong>DNS-01</strong> challenge. Make sure it’s set up in the Traefik configuration.

        </x-callout>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <x-forms.input canGate="update" :canResource="$server" id="entrypoints_input" :value="$entrypoints_input"
            label="Entrypoints (comma-separated)" placeholder="websecure"
            helper="Traefik entrypoints, e.g. http, https" />
        <x-forms.input canGate="update" :canResource="$server" id="tls_cert_resolver" :value="$tls_cert_resolver"
            label="TLS Cert Resolver" placeholder="letsencrypt"
            helper="Name of a Traefik certResolver. Leave empty if a wildcard cert is pre-mounted in tls.certificates." />
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <x-forms.select id="tls_enabled" label="TLS Enabled"
            helper="Terminate TLS on Traefik using the cert resolver above.">
            <option value="1" @selected($tls_enabled === '1')>Yes</option>
            <option value="0" @selected($tls_enabled === '0')>No</option>
        </x-forms.select>
        <x-forms.select id="https_redirect" label="HTTPS Redirect"
            helper="Add a web entrypoint router that redirects HTTP to HTTPS.">
            <option value="1" @selected($https_redirect === '1')>Yes</option>
            <option value="0" @selected($https_redirect === '0')>No</option>
        </x-forms.select>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <x-forms.select id="pass_host_header" label="Pass Host Header"
            helper="Forward the original Host header to the target (needed for downstream Traefik/vhosts).">
            <option value="1" @selected($pass_host_header === '1')>Yes</option>
            <option value="0" @selected($pass_host_header === '0')>No</option>
        </x-forms.select>
        <x-forms.select id="strip_prefix" label="Strip Path Prefix"
            helper="Remove the path prefix before forwarding to the target.">
            <option value="1" @selected($strip_prefix === '1')>Yes</option>
            <option value="0" @selected($strip_prefix === '0')>No</option>
        </x-forms.select>
    </div>

    <x-forms.button canGate="update" :canResource="$server" type="submit" @click="modalOpen=false">
        {{ $routerName ? 'Update Route' : 'Add Route' }}
    </x-forms.button>
</form>
