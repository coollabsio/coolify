<form wire:submit="submit" class="application-settings-form mb-6 flex flex-col gap-6">
    <x-unsaved-bar action="submit" />

    <x-application.settings-section id="replicas-section" title="Replicas"
        helper="Run more than one container of this application on one server. The proxy balances the traffic between them.">
        <x-slot:actions>
            <x-experimental-badge />
        </x-slot:actions>

        @if ($application->build_pack === 'dockercompose')
            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">{{ $unavailableReason }}</p>
        @else
            @if ($unavailableReason)
                <x-callout type="info" title="Replicas are unavailable" class="mb-4">{{ $unavailableReason }}</x-callout>
            @elseif ($application->persistentStorages()->exists())
                <x-callout type="warning" title="Replicas share persistent storage" class="mb-4">
                    All replicas use the same volumes. Make sure that your application supports this.
                </x-callout>
            @endif

            <div class="grid gap-4 lg:grid-cols-2">
                <x-forms.input id="replicas" type="number" min="1" :max="\App\Models\Application::MAX_REPLICAS" required
                    label="Replicas" :disabled="filled($unavailableReason)" canGate="update"
                    :canResource="$application" />
            </div>
            <p class="mt-3 text-[13px] text-neutral-500 dark:text-fg-dim">
                Replicas run on one server. To use several servers instead, add them below and put your own load
                balancer in front of them.
            </p>
        @endif
    </x-application.settings-section>

    @if ($application->build_pack !== 'dockercompose')
        <x-application.settings-section id="load-balancer-section" title="Load balancer"
            helper="How the proxy shares the requests between the replicas.">
            @if ($loadBalancerUnavailableReason)
                <x-callout type="info" title="Load balancer is unavailable">{{ $loadBalancerUnavailableReason }}</x-callout>
            @else
                <div x-data class="flex flex-col gap-4">
                    <x-callout type="info" title="Load balancer needs replicas" x-show="Number($wire.replicas) < 2">
                        Set more than one replica to choose how the proxy shares the requests between them.
                    </x-callout>
                    <fieldset x-bind:disabled="Number($wire.replicas) < 2" class="flex min-w-0 flex-col gap-4"
                        x-data="{ clientIp: @js(\App\Enums\LoadBalancerStrategy::ClientIp->value) }">
                        <div class="grid items-end gap-4 lg:grid-cols-2">
                            <x-forms.listbox id="loadBalancerStrategy" label="Strategy" :options="$strategies"
                                canGate="update" :canResource="$application" />
                            <div x-show="$wire.loadBalancerStrategy !== clientIp" class="-mx-2.5">
                                <x-forms.checkbox id="isStickySessionEnabled" label="Sticky sessions"
                                    helper="The proxy sets a cookie, so that the requests of one visitor go to the same replica. Use it when a replica keeps sessions in its memory."
                                    fullWidth canGate="update" :canResource="$application" />
                            </div>
                            <p x-show="$wire.loadBalancerStrategy === clientIp"
                                class="flex min-h-9 items-center text-[13px] text-neutral-500 dark:text-fg-dim">
                                Client IP already keeps each visitor on one replica.
                            </p>
                        </div>
                        <div x-show="$wire.isStickySessionEnabled && $wire.loadBalancerStrategy !== clientIp"
                            class="grid gap-4 lg:grid-cols-2">
                            <x-forms.input id="stickySessionCookieName" label="Cookie name" required
                                helper="Lowercase letters, digits, - and _, starting with a letter."
                                canGate="update" :canResource="$application" />
                            <x-forms.listbox id="stickySessionMaxAge" label="Keep the cookie for"
                                :options="$stickySessionDurations" :disabled="! $supportsStickySessionMaxAge"
                                :helper="$supportsStickySessionMaxAge ? null : 'The proxy of this server keeps the cookie until the browser closes. Traefik 3.0 or the current Caddy proxy can keep it longer.'"
                                canGate="update" :canResource="$application" />
                        </div>
                    </fieldset>
                </div>
            @endif
        </x-application.settings-section>
    @endif
</form>
