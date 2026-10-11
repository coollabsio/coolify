<div class="grid gap-4 sm:grid-cols-2">
    @if ($resourceType === 'application')
        @if (str($serviceApplication->image)->contains('pocketbase'))
            <x-forms.listbox id="isGzipEnabled" label="Gzip compression"
                helper="PocketBase keeps compression disabled so server-sent events continue to work."
                :disabled="true" :options="[
                    ['value' => true, 'label' => 'Enabled'],
                    ['value' => false, 'label' => 'Disabled'],
                ]" />
        @else
            <x-forms.listbox id="isGzipEnabled" label="Gzip compression"
                :options="[
                    ['value' => true, 'label' => 'Enabled'],
                    ['value' => false, 'label' => 'Disabled'],
                ]" />
        @endif
        <x-forms.listbox id="isStripprefixEnabled" label="Path prefixes"
            :options="[
                ['value' => true, 'label' => 'Strip prefixes'],
                ['value' => false, 'label' => 'Keep prefixes'],
            ]" />
        <x-forms.listbox id="excludeFromStatus" label="Service status"
            :options="[
                ['value' => false, 'label' => 'Include in status'],
                ['value' => true, 'label' => 'Exclude from status'],
            ]" />
        <x-forms.listbox id="isLogDrainEnabled" label="Log drain"
            :options="[
                ['value' => true, 'label' => 'Send logs to drain'],
                ['value' => false, 'label' => 'Do not drain logs'],
            ]" />
        <x-forms.input type="number" min="0" id="maxRestartCount" label="Max restart count"
            helper="Maximum number of Docker restarts before Coolify stops this container. Set to 0 to disable the limit. Docker counts expected and unexpected restarts."
            canGate="update" :canResource="$serviceApplication" />
        @if ($showCertificateResolver)
            <x-forms.listbox id="certificateResolverMode" label="Certificate resolver" live
                helper="Sets the Traefik certificate resolver for this service's HTTPS routes. Choose None for domains that use certificates from Traefik's dynamic configuration (for example Cloudflare Origin CA certificates). Restart or redeploy the service to apply the change."
                canGate="update" :canResource="$serviceApplication" :options="[
                    ['value' => 'server', 'label' => 'Server setting ('.$serverCertificateResolver.')'],
                    ['value' => 'none', 'label' => 'None (use your own certificates)'],
                    ['value' => 'custom', 'label' => 'Custom resolver'],
                ]" />
            @if ($certificateResolverMode === 'custom')
                <x-forms.input id="certificateResolverName" label="Certificate resolver name" placeholder="cloudflare"
                    required helper="Name of a certificate resolver defined in the Traefik configuration of this server."
                    canGate="update" :canResource="$serviceApplication" />
            @endif
        @endif
    @else
        <x-forms.listbox id="excludeFromStatus" label="Service status"
            :options="[
                ['value' => false, 'label' => 'Include in status'],
                ['value' => true, 'label' => 'Exclude from status'],
            ]" />
        <x-forms.listbox id="isLogDrainEnabled" label="Log drain"
            :options="[
                ['value' => true, 'label' => 'Send logs to drain'],
                ['value' => false, 'label' => 'Do not drain logs'],
            ]" />
    @endif
</div>
