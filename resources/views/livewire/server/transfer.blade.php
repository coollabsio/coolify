<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Transfer | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-4 md:gap-8 md:flex-row">
        <x-server.sidebar :server="$server" activeMenu="transfer" />
        <div class="flex w-full flex-col gap-6">
            <div>
                <h2>Transfer server</h2>
                <div class="subtitle">
                    Move this server’s control-plane config to another Coolify instance (same physical host).
                </div>
            </div>

            @if ($this->isLocalhost)
                <div class="rounded-lg border border-warning/40 bg-warning/10 p-4 text-sm text-warning">
                    The Coolify host (localhost) cannot be transferred between instances.
                </div>
            @else
                <div class="rounded-lg border border-neutral-200 p-3 text-sm dark:border-coolgray-200">
                    <span class="font-semibold">Status:</span>
                    <span class="font-mono">{{ $this->transferStatus ?: 'ready' }}</span>
                    @if ($exportId)
                        <span class="ml-2 text-xs text-neutral-500">export {{ $exportId }}</span>
                    @endif
                </div>

                {{-- One-click migrate --}}
                <section class="flex flex-col gap-4 rounded-lg border border-neutral-200 p-4 dark:border-coolgray-200">
                    <div>
                        <h3>Transfer to another instance</h3>
                        <p class="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                            Enter the target Coolify URL and an API token from that instance (root recommended).
                            This exports the server, imports and claims it on the target, then disables automations here.
                        </p>
                    </div>
                    <div class="flex flex-col gap-3 md:max-w-xl">
                        <x-forms.input id="targetUrl" label="Target instance URL" required
                            placeholder="http://localhost:8001" helper="Base URL of the other Coolify instance (no trailing path)." />
                        <x-forms.input id="targetToken" type="password" label="Target API token" required
                            placeholder="Paste token from target instance" autocomplete="off"
                            helper="Create a token on the target with root (or write + create servers). It is only used for this request." />
                        <x-forms.checkbox id="writeRemote" label="Write ownership file on the host via SSH (optional)" />
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
                        <div class="rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
                            <div class="mb-1 font-semibold text-warning">Warnings</div>
                            <ul class="list-disc space-y-1 pl-5">
                                @foreach ($lastWarnings as $warning)
                                    <li>{{ $warning }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    @if ($lastResultJson)
                        <div>
                            <div class="mb-1 text-sm font-semibold">Result</div>
                            <pre class="max-h-64 overflow-auto rounded-lg bg-neutral-100 p-3 text-xs dark:bg-coolgray-100">{{ $lastResultJson }}</pre>
                        </div>
                    @endif
                </section>

                {{-- Advanced --}}
                <section class="rounded-lg border border-neutral-200 dark:border-coolgray-200" x-data="{ open: @entangle('showAdvanced') }">
                    <button type="button" class="flex w-full items-center justify-between p-4 text-left"
                        @click="open = !open">
                        <span class="font-semibold">Advanced</span>
                        <span class="text-sm text-neutral-500" x-text="open ? 'Hide' : 'Show'"></span>
                    </button>
                    <div class="flex flex-col gap-6 border-t border-neutral-200 p-4 dark:border-coolgray-200" x-show="open" x-cloak>
                        <div>
                            <h4 class="font-medium">Download bundle</h4>
                            <p class="mb-3 text-sm text-neutral-500">Manual / air-gapped transfer. Import on the target via Servers → Import transfer.</p>
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
                                <a href="{{ route('server.transfer.import') }}" {{ wireNavigate() }}>
                                    <x-forms.button>Import page (this instance)</x-forms.button>
                                </a>
                            </div>
                        </div>

                        <div>
                            <h4 class="font-medium">Complete only</h4>
                            <p class="mb-3 text-sm text-neutral-500">Disable automations after a manual import on the target.</p>
                            <x-forms.button canGate="update" :canResource="$server" wire:click="completeTransfer"
                                wire:loading.attr="disabled"
                                wire:confirm="Disable automations on this server?">
                                Mark transferred & disable automations
                            </x-forms.button>
                        </div>

                        <div>
                            <h4 class="font-medium">Re-claim only</h4>
                            <p class="mb-3 text-sm text-neutral-500">Retry claim on this instance (after a local import).</p>
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
                </section>
            @endif
        </div>
    </div>
</div>
