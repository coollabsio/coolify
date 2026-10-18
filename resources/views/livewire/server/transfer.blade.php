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
                        @if ($server->isManagementDisabled())
                            <x-status-badge label="Transferable" type="warning" />
                        @elseif ($server->isTransferredAway())
                            <x-status-badge label="Transferred away" type="warning" />
                        @else
                            <x-status-badge label="Managed here" type="success" />
                        @endif
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
                                This exports the server and imports it on the target, which then manages it. Management
                                is then disabled here.
                            </p>
                        </div>
                        <div class="flex flex-col gap-3 md:max-w-xl">
                            <x-forms.input id="targetUrl" label="Target instance URL" required
                                placeholder="http://localhost:8001"
                                helper="Base URL of the other Coolify instance (no trailing path)." />
                            <x-forms.input id="targetToken" type="password" label="Target API token" required
                                placeholder="Paste token from target instance" autocomplete="off"
                                helper="Create a token on the target with root (or write + create servers). It is only used for this request." />
                        </div>
                        <div>
                            <x-modal-confirmation title="Transfer this server?" submitAction="migrateServer"
                                :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Transfer server"
                                :actions="[
                                    'The server will be transferred to the target instance.',
                                    'Automations for this server will be disabled on this instance.',
                                ]">
                                <x-slot:trigger>
                                    <x-forms.button canGate="update" :canResource="$server" wire:loading.attr="disabled"
                                        wire:target="migrateServer">
                                        <span wire:loading.remove wire:target="migrateServer">Transfer server</span>
                                        <span wire:loading wire:target="migrateServer">Transferring…</span>
                                    </x-forms.button>
                                </x-slot:trigger>
                            </x-modal-confirmation>
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

                <x-application.settings-section id="server-transfer-manual-section" title="Manual transfer"
                    helper="Download the server as a file, for example when the instances cannot reach each other.">
                    <div class="flex flex-col gap-4">
                        <p class="text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                            Import the file on the target via Servers → Import server, then click Disable management
                            on this server.
                        </p>
                        <div class="flex flex-col gap-3 md:max-w-xl">
                            <x-forms.input id="passphrase" type="password" label="Passphrase"
                                placeholder="Optional"
                                helper="If you set a passphrase, the file is encrypted with it. You need the same passphrase to import the file."
                                autocomplete="new-password" />
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <x-forms.button canGate="view" :canResource="$server" wire:click="exportBundle"
                                wire:loading.attr="disabled">
                                <span wire:loading.remove wire:target="exportBundle">Download JSON</span>
                                <span wire:loading wire:target="exportBundle">Exporting…</span>
                            </x-forms.button>
                            <a href="{{ route('server.transfer.import') }}" {{ wireNavigate() }} class="button">
                                Import page (this instance)
                            </a>
                        </div>
                    </div>
                </x-application.settings-section>
            @endif
        </div>
    </div>
</div>
