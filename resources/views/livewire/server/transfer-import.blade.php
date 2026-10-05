<div class="w-full">
    <x-slot:title>
        Import server | Coolify
    </x-slot>

    <div class="mb-5 flex min-h-9 flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="min-w-0 text-[24px]! leading-7! font-semibold! tracking-tight!">Import server</h1>
                <x-status-badge label="Dev" />
            </div>
            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                Import a server that you downloaded from another Coolify instance. This instance then manages it.
            </p>
        </div>
        <a href="{{ route('server.index') }}" class="button w-fit shrink-0" {{ wireNavigate() }}>
            Back to servers
        </a>
    </div>

    <div class="application-settings-form flex flex-col gap-6">
        <x-application.settings-section id="server-import-file-section" title="Transfer file"
            helper="On the source instance, open the server, go to Transfer, and click Download JSON in Manual transfer. The server data on the machine does not change.">
            <x-slot:actions>
                <x-forms.button wire:click="dryRun" wire:loading.attr="disabled" wire:target="dryRun,importBundle">
                    <x-reicon name="search" class="size-3.5" />
                    <span wire:loading.remove wire:target="dryRun">Dry run</span>
                    <span wire:loading wire:target="dryRun">Checking…</span>
                </x-forms.button>
                <x-modal-confirmation title="Import this server?" submitAction="importBundle"
                    :confirmWithText="false" :confirmWithPassword="false" step2ButtonText="Import server"
                    :actions="[
                        'The server and its resources will be added to the current team.',
                        'This instance will manage the server. Disable management of it on the source instance.',
                    ]">
                    <x-slot:trigger>
                        <x-forms.button isHighlighted wire:loading.attr="disabled" wire:target="dryRun,importBundle">
                            <x-reicon name="upload" class="size-3.5" />
                            <span wire:loading.remove wire:target="importBundle">Import server</span>
                            <span wire:loading wire:target="importBundle">Importing…</span>
                        </x-forms.button>
                    </x-slot:trigger>
                </x-modal-confirmation>
            </x-slot:actions>

            <div class="flex flex-col gap-4">
                <x-forms.input type="file" id="bundleFile" label="Upload file"
                    accept=".json,application/json" />
                <x-forms.textarea id="bundleJson" label="Or paste the file content" rows="10"
                    placeholder='{"schema_version":1,...}' />
                <div class="md:max-w-xl">
                    <x-forms.input id="passphrase" type="password" label="Passphrase"
                        placeholder="Only for encrypted files" autocomplete="off" />
                </div>
            </div>
        </x-application.settings-section>

        <x-application.settings-section id="server-import-options-section" title="Options">
            <div class="flex flex-col gap-1 md:max-w-xl">
                <x-forms.checkbox id="preserveUuids" label="Keep UUIDs from the source instance"
                    helper="Resources keep the same UUIDs, so their names, containers, and webhook URLs stay the same." />
                <x-forms.checkbox id="adoptMode" label="Adopt running resources"
                    helper="Keep the current status of each resource and do not redeploy stopped resources." />
            </div>
        </x-application.settings-section>

        @if (count($lastWarnings) > 0)
            <x-callout type="warning" title="Warnings">
                <ul class="mt-1 list-disc space-y-1 pl-5">
                    @foreach ($lastWarnings as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </x-callout>
        @endif

        @if ($lastResult)
            @php
                $createdCounts = collect(data_get($lastResult, 'created', []))
                    ->filter(fn ($count) => (int) $count > 0);
            @endphp
            <x-application.settings-section id="server-import-result-section"
                :title="data_get($lastResult, 'dry_run') ? 'Dry run result' : 'Import result'">
                <x-slot:actions>
                    @if (data_get($lastResult, 'dry_run'))
                        <x-status-badge label="Nothing written" type="neutral" />
                    @elseif (data_get($lastResult, 'claimed'))
                        <x-status-badge label="Managed here" type="success" />
                    @else
                        <x-status-badge label="Management not enabled" type="warning" />
                    @endif
                    @if ($importedServerUuid)
                        <a href="{{ route('server.show', ['server_uuid' => $importedServerUuid]) }}" class="button"
                            {{ wireNavigate() }}>
                            Open server
                            <x-reicon name="arrow-right" class="size-3.5" />
                        </a>
                    @endif
                </x-slot:actions>

                <div class="flex flex-col gap-4">
                    @if (filled(data_get($lastResult, 'server_uuid')))
                        <p class="text-xs text-neutral-500 dark:text-fg-dim">
                            Server UUID
                            <code class="ml-1 font-mono text-neutral-700 dark:text-fg">{{ data_get($lastResult, 'server_uuid') }}</code>
                        </p>
                    @endif

                    @if ($createdCounts->isNotEmpty())
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                            @foreach ($createdCounts as $type => $count)
                                <div
                                    class="rounded-lg border border-neutral-200 px-3 py-2 dark:border-white/[0.08]">
                                    <div class="text-[15px] font-semibold text-neutral-950 dark:text-fg">
                                        {{ $count }}</div>
                                    <div class="text-[11px] text-neutral-500 dark:text-fg-faint">
                                        {{ str($type)->replace('_', ' ')->ucfirst() }}</div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-xs text-neutral-500 dark:text-fg-dim">No resources in this file.</p>
                    @endif
                </div>
            </x-application.settings-section>
        @endif
    </div>
</div>
