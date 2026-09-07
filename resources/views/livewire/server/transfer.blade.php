<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Transfer | Coolify
    </x-slot>

    <livewire:server.navbar :server="$server" />

    <div
        class="server-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-server.sidebar :server="$server" activeMenu="transfer" />

        <div class="application-settings-form flex w-full flex-col gap-6">
            @if ($this->isLocalhost)
                <x-application.settings-section id="server-transfer-section" title="Transfer server (Dev)"
                    helper="Move this server’s control-plane config to another Coolify instance (same physical host).">
                    <x-callout type="warning" title="Localhost cannot be transferred">
                        The Coolify host (localhost) cannot be transferred between instances.
                    </x-callout>
                </x-application.settings-section>
            @else
                <x-application.settings-section id="server-transfer-section" title="Transfer server (Dev)"
                    helper="Move this server’s control-plane config to another Coolify instance (same physical host).">
                    <x-slot:actions>
                        <x-status-badge
                            :label="$this->transferStatus ?: 'ready'"
                            type="neutral" />
                        @if ($exportId)
                            <span class="text-[11px] text-neutral-500 dark:text-fg-faint">export {{ $exportId }}</span>
                        @endif
                    </x-slot:actions>

                    <div class="flex flex-col gap-4">
                        <div>
                            <h3 class="text-sm font-semibold text-neutral-950 dark:text-fg">Transfer to another instance
                            </h3>
                            <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                                Enter the target Coolify URL and an API token from that instance (root recommended).
                                This exports the server, imports and claims it on the target, then disables automations
                                here.
                            </p>
                        </div>
                        <div class="flex flex-col gap-3 md:max-w-xl">
                            <x-forms.input id="targetUrl" label="Target instance URL" required
                                placeholder="http://localhost:8001"
                                helper="Base URL of the other Coolify instance (no trailing path)." />
                            <x-forms.input id="targetToken" type="password" label="Target API token" required
                                placeholder="Paste token from target instance" autocomplete="off"
                                helper="Create a token on the target with root (or write + create servers). It is only used for this request." />
                            <x-forms.checkbox id="writeRemote"
                                label="Write ownership file on the host via SSH (optional)" />
                        </div>
                        <div>
                            <x-forms.button canGate="update" :canResource="$server" wire:click="migrateServer"
                                wire:loading.attr="disabled"
                                wire:confirm="Transfer this server to the target instance? Automations will be disabled here.">
                                <span wire:loading.remove wire:target="migrateServer">Transfer server</span>
                                <span wire:loading wire:target="migrateServer">Transferring…</span>
                            </x-forms.button>
                        </div>
                        @if (count($lastWarnings) > 0)
                            <x-callout type="warning" title="Warnings">
                                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                                    @foreach ($lastWarnings as $warning)
                                        <li>{{ $warning }}</li>
                                    @endforeach
                                </ul>
                            </x-callout>
                        @endif
                        @if ($lastResultJson)
                            <div>
                                <div class="mb-1 text-sm font-semibold">Result</div>
                                <pre
                                    class="max-h-64 overflow-auto rounded-lg bg-neutral-100 p-3 text-xs dark:bg-coolgray-100">{{ $lastResultJson }}</pre>
                            </div>
                        @endif
                    </div>
                </x-application.settings-section>

                <x-application.settings-section id="server-transfer-advanced-section" title="Advanced"
                    helper="Manual export, complete-only, and re-claim helpers for air-gapped or partial transfers.">
                    <div class="flex flex-col gap-6" x-data="{ open: @entangle('showAdvanced') }">
                        <button type="button"
                            class="flex w-full items-center justify-between rounded-lg border border-neutral-200 px-4 py-3 text-left dark:border-white/[0.08]"
                            @click="open = !open">
                            <span class="text-sm font-semibold">Show advanced options</span>
                            <span class="text-xs text-neutral-500 dark:text-fg-faint"
                                x-text="open ? 'Hide' : 'Show'"></span>
                        </button>

                        <div class="flex flex-col gap-6" x-show="open" x-cloak>
                            <div>
                                <h4 class="text-sm font-medium">Download bundle</h4>
                                <p class="mb-3 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                                    Manual / air-gapped transfer. Import on the target via Servers → Import transfer.
                                </p>
                                <div class="mb-3 flex flex-col gap-3 md:max-w-xl">
                                    <x-forms.checkbox id="encryptBundle" label="Encrypt with passphrase" />
                                    <x-forms.input id="passphrase" type="password" label="Passphrase"
                                        placeholder="Used when encrypt is checked" autocomplete="new-password" />
                                </div>
                                <div class="flex flex-wrap gap-2">
                                    <x-forms.button canGate="view" :canResource="$server" wire:click="exportBundle"
                                        wire:loading.attr="disabled">
                                        <span wire:loading.remove wire:target="exportBundle">Download JSON</span>
                                        <span wire:loading wire:target="exportBundle">Exporting…</span>
                                    </x-forms.button>
                                    <a href="{{ route('server.transfer.import') }}" {{ wireNavigate() }}
                                        class="button">
                                        Import page (this instance)
                                    </a>
                                </div>
                            </div>

                            <div>
                                <h4 class="text-sm font-medium">Complete only</h4>
                                <p class="mb-3 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                                    Disable automations after a manual import on the target.
                                </p>
                                <x-forms.button canGate="update" :canResource="$server" wire:click="completeTransfer"
                                    wire:loading.attr="disabled"
                                    wire:confirm="Disable automations on this server?">
                                    Mark transferred & disable automations
                                </x-forms.button>
                            </div>

                            <div>
                                <h4 class="text-sm font-medium">Re-claim only</h4>
                                <p class="mb-3 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                                    Retry claim on this instance (after a local import).
                                </p>
                                <div class="mb-3 flex flex-col gap-2">
                                    <x-forms.checkbox id="writeRemoteOnClaim" label="Write ownership file via SSH" />
                                    <x-forms.checkbox id="rebindSentinelOnClaim" label="Rebind Sentinel" />
                                </div>
                                <x-forms.button canGate="update" :canResource="$server" wire:click="claimServer"
                                    wire:loading.attr="disabled">
                                    Re-claim
                                </x-forms.button>
                            </div>
                        </div>
                    </div>
                </x-application.settings-section>
            @endif
        </div>
    </div>
</div>
