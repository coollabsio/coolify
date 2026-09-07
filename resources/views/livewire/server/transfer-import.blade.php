<div>
    <x-slot:title>
        Import server transfer | Coolify
    </x-slot>
    <div class="flex flex-col gap-6">
        <div class="flex flex-wrap items-center gap-2">
            <h1>Import server transfer <x-status-badge label="Dev" /></h1>
            <a href="{{ route('server.index') }}" {{ wireNavigate() }}>
                <x-forms.button>Back to servers</x-forms.button>
            </a>
        </div>
        <div class="subtitle">
            Paste or upload a transfer bundle exported from another Coolify instance. This creates the server and its
            resources under the current team and <strong>claims</strong> the host for this instance
            (control-plane only — host data stays on the machine).
        </div>

        <div class="flex flex-col gap-4 rounded-lg border border-neutral-200 p-4 dark:border-coolgray-200">
            <x-forms.input type="file" id="bundleFile" label="Bundle file (.json)" accept=".json,application/json" />
            <x-forms.textarea id="bundleJson" label="Or paste bundle JSON" rows="14" placeholder='{"schema_version":1,...}' />
            <x-forms.input id="passphrase" type="password" label="Passphrase (if encrypted)" placeholder="Optional" />
            <div class="flex flex-col gap-2">
                <x-forms.checkbox id="preserveUuids" label="Preserve UUIDs from the source instance" />
                <x-forms.checkbox id="adoptMode" label="Adopt mode (keep statuses; do not force exited redeploy)" />
                <x-forms.checkbox id="writeRemote" label="Also write ownership file on the host via SSH (optional)" />
            </div>
            <div class="flex flex-wrap gap-2">
                <x-forms.button wire:click="dryRun" wire:loading.attr="disabled" wire:target="dryRun,importBundle">
                    <span wire:loading.remove wire:target="dryRun">Dry run</span>
                    <span wire:loading wire:target="dryRun">Checking…</span>
                </x-forms.button>
                <x-forms.button wire:click="importBundle" wire:loading.attr="disabled"
                    wire:target="dryRun,importBundle"
                    wire:confirm="Import this server into the current team?">
                    <span wire:loading.remove wire:target="importBundle">Import server</span>
                    <span wire:loading wire:target="importBundle">Importing…</span>
                </x-forms.button>
            </div>
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

        @if ($lastResult)
            <div class="rounded-lg border border-neutral-200 p-4 dark:border-coolgray-200">
                <div class="mb-2 font-semibold">
                    {{ data_get($lastResult, 'dry_run') ? 'Dry-run result' : 'Import result' }}
                </div>
                @if ($importedServerUuid)
                    <div class="mb-3 flex flex-wrap items-center gap-2 text-sm">
                        <span>Server UUID: <code class="font-mono">{{ $importedServerUuid }}</code></span>
                        @if (data_get($lastResult, 'claimed'))
                            <span class="text-success">Claimed</span>
                        @endif
                        <a href="{{ route('server.show', ['server_uuid' => $importedServerUuid]) }}" {{ wireNavigate() }}>
                            <x-forms.button>Open server</x-forms.button>
                        </a>
                        <a href="{{ route('server.transfer', ['server_uuid' => $importedServerUuid]) }}" {{ wireNavigate() }}>
                            <x-forms.button>Transfer details</x-forms.button>
                        </a>
                    </div>
                @endif
                <pre class="max-h-80 overflow-auto rounded-lg bg-neutral-100 p-3 text-xs dark:bg-coolgray-100">{{ json_encode($lastResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
            </div>
        @endif
    </div>
</div>
