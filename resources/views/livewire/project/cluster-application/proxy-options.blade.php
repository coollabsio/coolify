<div>
    <x-application.settings-section id="cluster-application-proxy-options" title="Proxy options"
        helper="Applies to all domains of this application. Ingress servers apply a change without a redeploy.">
        <form wire:submit="save" class="application-settings-form flex flex-col gap-4">
            @unless ($supported)
                <x-callout type="warning" title="Upgrade Sentinel to use these options">
                    Basic auth, the IP allow list, headers, and compression need Sentinel with ingress.route_options.v1
                    on every server of the cluster. Until then, Coolify cannot save basic auth or an IP allow list,
                    and ingress does not apply headers and compression.
                </x-callout>
            @endunless
            @error('proxyOptions')
                <x-callout type="danger" title="The proxy options were not saved">{{ $message }}</x-callout>
            @enderror

            <div class="flex flex-col gap-3">
                <x-forms.checkbox id="basicAuthEnabled" label="Basic auth" canGate="update" :canResource="$workload"
                    helper="Ingress asks for a username and a password before a request reaches the application. Coolify sends only a bcrypt hash of the password to the servers." />
                <div class="grid gap-4 lg:grid-cols-2" x-show="$wire.basicAuthEnabled" x-cloak>
                    <x-forms.input id="basicAuthUsername" label="Username" autocomplete="off" canGate="update"
                        :canResource="$workload" />
                    <div class="flex items-end gap-2">
                        <div class="grow">
                            <x-forms.input type="password" id="basicAuthPassword" label="Password"
                                autocomplete="new-password" canGate="update" :canResource="$workload"
                                helper="8 to 72 printable ASCII characters. Generate makes a random password." />
                        </div>
                        @can('update', $workload)
                            <x-forms.button type="button" wire:click="generatePassword" wire:target="generatePassword">
                                Generate
                            </x-forms.button>
                        @endcan
                    </div>
                </div>
            </div>

            <x-forms.textarea id="allowedIps" label="IP allow list" rows="3" monospace canGate="update"
                :canResource="$workload" placeholder="203.0.113.0/24&#10;2001:db8::/32"
                helper="One IPv4 or IPv6 address or CIDR range on each line, at most 32. Other addresses get 403. Ingress checks the address that connects to it, so behind a CDN or a load balancer it sees the address of that proxy." />

            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.textarea id="requestHeaders" label="Request headers" rows="3" monospace canGate="update"
                    :canResource="$workload" placeholder="X-Team: web"
                    helper="One Name: Value on each line, at most 16. Ingress sets them on each request to the application. Every server of the cluster stores them as plain text, so do not put a token here." />
                <x-forms.textarea id="responseHeaders" label="Response headers" rows="3" monospace canGate="update"
                    :canResource="$workload" placeholder="X-Frame-Options: DENY"
                    helper="One Name: Value on each line, at most 16. Ingress sets them on each response, also when the application sets the same header." />
            </div>

            <div class="grid gap-2 lg:grid-cols-2">
                <x-forms.checkbox id="gzipEnabled" label="Compression" canGate="update" :canResource="$workload"
                    helper="Ingress compresses responses with zstd or gzip when the client accepts it." />
                <x-forms.checkbox id="stripPrefixEnabled" label="Strip the path prefix" canGate="update"
                    :canResource="$workload"
                    helper="For a domain with a path, like /api, ingress removes the path before the request reaches the application." />
            </div>

            @can('update', $workload)
                <div class="flex justify-end">
                    <x-forms.button type="submit" wire:target="save" isHighlighted>Save</x-forms.button>
                </div>
            @endcan
        </form>
    </x-application.settings-section>
</div>
