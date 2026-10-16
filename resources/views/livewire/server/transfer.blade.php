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
                <x-application.settings-section id="server-transfer-section" title="Transfer server"
                    helper="Move this server’s control-plane config to another Coolify instance (same physical host).">
                    <x-callout type="warning" title="Localhost cannot be transferred">
                        The Coolify host (localhost) cannot be transferred between instances.
                    </x-callout>
                </x-application.settings-section>
            @else
                <x-application.settings-section id="server-transfer-section" title="Transfer server"
                    helper="Move this server’s control-plane config to another Coolify instance (same physical host).">
                    <x-slot:actions>
                        @php
                            $exportTooltip = $exportId ? "Export ID: {$exportId}" : null;
                        @endphp
                        @if (data_get($this->migration, 'status') === 'running')
                            <x-status-badge label="Transferring" type="warning" :data-tooltip="$exportTooltip" />
                        @elseif ($server->isTransferredAway())
                            <x-status-badge label="Transferred away" type="warning" :data-tooltip="$exportTooltip" />
                        @else
                            <x-status-badge label="Managed here" type="success" :data-tooltip="$exportTooltip" />
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
                                placeholder="https://coolify.example.com"
                                helper="Base URL of the other Coolify instance (no trailing path). It must use https." />
                            <x-forms.input id="targetToken" type="password" label="Target API token" required
                                placeholder="Paste token from target instance" autocomplete="off"
                                helper="Create a token on the target with root (or write + create servers). It is only used for this request." />
                        </div>
                        <div>
                            <x-modal-confirmation title="Transfer this server?" submitAction="migrateServer"
                                :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Transfer server"
                                :actions="[
                                    'Coolify first checks this server, the target instance, and the token. If a check fails, nothing is changed.',
                                    'The server will be transferred to the target instance.',
                                    'Automations for this server will be disabled on this instance.',
                                ]">
                                <x-slot:trigger>
                                    <x-forms.button canGate="update" :canResource="$server" wire:loading.attr="disabled"
                                        wire:target="migrateServer">
                                        <span wire:loading.remove wire:target="migrateServer">Transfer server</span>
                                        <span wire:loading wire:target="migrateServer">Running checks…</span>
                                    </x-forms.button>
                                </x-slot:trigger>
                            </x-modal-confirmation>
                        </div>
                        @php $migration = $this->migration; @endphp
                        @if (data_get($migration, 'status') === 'running')
                            <div wire:poll.5s="refreshMigration">
                                <x-callout type="info" title="Transfer in progress">
                                    The server is being transferred to {{ data_get($migration, 'target_url') }}. You can
                                    leave this page; the transfer continues in the background.
                                </x-callout>
                            </div>
                        @elseif (data_get($migration, 'status') === 'failed')
                            <x-callout type="danger"
                                :title="data_get($migration, 'phase') === 'checks' ? 'A check failed. The transfer did not start.' : 'Transfer failed'">
                                @if (count((array) data_get($migration, 'log', [])) > 0)
                                    See the transfer log below for details.
                                @else
                                    {{ data_get($migration, 'error') }}
                                @endif
                            </x-callout>
                        @elseif (data_get($migration, 'status') === 'unknown')
                            <x-callout type="warning" title="Transfer result unknown">
                                The transfer stopped before this instance could confirm the result. Check whether
                                {{ data_get($migration, 'target_url') }} manages this server. If it does, call
                                <code>POST /api/v1/servers/{{ $server->uuid }}/transfer/complete</code> on this instance.
                                If it does not, transfer again.
                            </x-callout>
                        @elseif (data_get($migration, 'status') === 'succeeded')
                            <x-callout type="success" title="Transfer finished">
                                {{ data_get($migration, 'target_url') }} now manages this server. Automations are
                                disabled on this instance.
                            </x-callout>
                            @php $checklist = $this->postTransferChecklist; @endphp
                            @if (count($checklist) > 0)
                                <div x-data="{ done: [] }" data-testid="post-transfer-checklist">
                                    <div class="flex items-baseline justify-between gap-3">
                                        <h3 class="text-sm font-semibold text-neutral-950 dark:text-fg">After the transfer</h3>
                                        <span class="text-xs text-neutral-500 dark:text-fg-dim"
                                            x-text="`${done.length} of {{ count($checklist) }} done`"></span>
                                    </div>
                                    <p class="mt-1 text-xs leading-5 text-neutral-500 dark:text-fg-dim">
                                        Do these steps to finish the move. The target instance cannot do them for you.
                                    </p>
                                    <div class="mt-2 rounded-lg border border-neutral-200 p-1 dark:border-white/[0.08]">
                                        @foreach ($checklist as $step)
                                            @php $stepKey = hash('xxh128', $step); @endphp
                                            <div wire:key="post-transfer-step-{{ $stepKey }}">
                                                <x-forms.checkbox :id="'post-transfer-step-' . $stepKey" :label="e($step)"
                                                    :domValue="$stepKey" fullWidth x-model="done" />
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                        @endif
                        @if (count((array) data_get($migration, 'log', [])) > 0)
                            <div>
                                <h3 class="text-sm font-semibold text-neutral-950 dark:text-fg">Transfer log</h3>
                                <ol class="mt-2 flex flex-col gap-1 rounded-lg border border-neutral-200 bg-neutral-50 p-3 font-mono text-xs dark:border-coolgray-300 dark:bg-coolgray-100"
                                    data-testid="transfer-log">
                                    @foreach ((array) data_get($migration, 'log', []) as $entry)
                                        <li wire:key="transfer-log-{{ $loop->index }}-{{ data_get($entry, 'at') }}" @class([
                                            'flex gap-3',
                                            'text-success' => data_get($entry, 'level') === 'success',
                                            'text-error' => data_get($entry, 'level') === 'error',
                                            'text-warning' => data_get($entry, 'level') === 'warning',
                                            'text-neutral-600 dark:text-fg-dim' => data_get($entry, 'level') === 'info',
                                        ])>
                                            <span class="shrink-0 text-neutral-400">{{ \Illuminate\Support\Carbon::parse(data_get($entry, 'at'))->format('H:i:s') }}</span>
                                            <span class="break-words">{{ data_get($entry, 'message') }}</span>
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        @endif
                        @if (count($lastWarnings) > 0)
                            <x-callout type="warning" title="Warnings">
                                <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
                                    @foreach ($lastWarnings as $warning)
                                        <li>{{ $warning }}</li>
                                    @endforeach
                                </ul>
                            </x-callout>
                        @endif
                    </div>
                </x-application.settings-section>

            @endif
        </div>
    </div>
</div>
