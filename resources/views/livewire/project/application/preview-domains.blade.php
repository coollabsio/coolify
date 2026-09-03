<div class="flex flex-col gap-3" x-data="{ editOpen: false }"
    @open-preview-domain-edit.window="editOpen = true"
    @close-preview-domain-edit.window="editOpen = false">
    @if (collect($domainRows)->contains(fn ($row) => $row['dns_status'] === 'checking'))
        <div class="hidden" wire:poll.2000ms="pollDnsChecks" aria-hidden="true"></div>
    @endif
    <div class="flex flex-wrap items-center gap-2">
        <p class="min-w-0 flex-1 text-[13px] text-neutral-500 dark:text-fg-dim">
            {{ count($domainRows) }} domain{{ count($domainRows) === 1 ? '' : 's' }}
        </p>
        @can('update', $preview->application)
            @if (count($domainRows) > 0)
                <x-forms.button wire:click="checkAllDns" wire:loading.attr="disabled" wire:target="checkAllDns,checkDomainDns">
                    <x-reicon name="refresh" class="size-3.5" />
                    Recheck DNS
                </x-forms.button>
            @endif
            <x-modal-input title="Add domain" :closeOutside="false" :wireIgnore="false"
                canGate="update" :canResource="$preview->application">
                <x-slot:content>
                    <button type="button" class="button button-highlighted">
                        <x-reicon name="plus" class="size-3.5" />
                        Add
                    </button>
                </x-slot:content>
                <form wire:submit="addDomain" class="application-settings-form flex flex-col gap-4">
                    @if ($isCompose && count($composeServices) > 0)
                        <x-forms.listbox id="newDomainService" label="Service" required
                            :options="collect($composeServices)->map(fn ($service) => ['value' => $service, 'label' => $service])->all()" />
                    @endif
                    <x-forms.domain-input id="newDomainParts" />

                    <div class="flex flex-wrap items-center justify-between gap-2 pt-2">
                        <x-forms.button type="button" wire:click="generateDomain">Generate domain</x-forms.button>
                        <x-forms.button type="submit" isHighlighted>Save</x-forms.button>
                    </div>
                </form>
            </x-modal-input>
        @endcan
    </div>

    @if (count($domainRows) === 0)
        <div class="application-settings-section-body">
            <x-empty size="sm" title="No domains configured"
                description="Add a domain or generate one with the server wildcard domain." icon-name="globe" />
        </div>
    @else
        <div class="application-settings-section-body is-flush overflow-visible">
            <div class="data-table-header domains-table-grid-service">
                <span>Domain</span>
                <span>DNS check</span>
                <span></span>
                <span></span>
            </div>
            @foreach ($domainRows as $index => $row)
                @php
                    $dnsType = match ($row['dns_status']) {
                        'ok' => 'success',
                        'failed' => 'error',
                        'skipped' => 'warning',
                        default => 'neutral',
                    };
                    $dnsLabel = match ($row['dns_status']) {
                        'ok' => 'DNS OK',
                        'failed' => 'DNS mismatch',
                        'skipped' => 'DNS skipped',
                        default => 'DNS pending',
                    };
                    $domainKey = hash('sha256', $row['url'].'|'.($row['service'] ?? ''));
                @endphp
                <div wire:key="preview-domain-{{ md5(($row['service'] ?? '') . $row['url']) }}" class="env-table-item">
                    <div class="data-table-row domains-table-grid-service">
                        <div class="flex min-w-0 flex-col gap-1">
                            <div class="flex min-w-0 flex-wrap items-center gap-2">
                                <x-reicon name="globe" class="size-4 shrink-0 text-neutral-400 dark:text-fg-faint" />
                                <a href="{{ getFqdnWithoutPort($row['url']) }}" target="_blank"
                                    class="min-w-0 flex-1 text-[13px] text-black underline decoration-neutral-300 underline-offset-2 hover:decoration-coollabs sm:truncate dark:text-fg dark:decoration-white/20 dark:hover:decoration-warning"
                                    title="{{ $row['url'] }}">{{ $row['url'] }}</a>
                                @if (filled($row['internal_port'] ?? null) && (int) $row['internal_port'] > 0)
                                    <span class="table-badge shrink-0"
                                        title="{{ ($row['has_port_override'] ?? false) ? 'Custom internal port for this domain' : 'Inherited from Ports Exposes' }}">
                                        Internal port {{ $row['internal_port'] }}
                                    </span>
                                @else
                                    <span class="table-badge table-badge-danger shrink-0"
                                        title="Set Ports Exposes or a per-domain internal port so the proxy can route this domain.">
                                        No internal port
                                    </span>
                                @endif
                                @if (filled($row['service']))
                                    <span class="table-badge shrink-0">{{ $row['service'] }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="flex min-w-0 items-center">
                            <x-status-badge :status="$dnsLabel" :type="$dnsType" :title="$row['dns_message']" />
                        </div>
                        <div class="hidden"></div>
                        <div class="flex items-center justify-end gap-1">
                            @can('update', $preview->application)
                                <button type="button" wire:click="checkDomainDns({{ $index }})"
                                    wire:loading.attr="disabled"
                                    wire:target="checkDomainDns({{ $index }}),checkAllDns"
                                    class="icon-button shrink-0" title="Check DNS" aria-label="Check DNS">
                                    <x-reicon name="refresh" class="size-3.5" wire:loading.remove.delay
                                        wire:target="checkDomainDns({{ $index }}),checkAllDns" />
                                    <x-loading-on-button wire:loading.delay
                                        wire:target="checkDomainDns({{ $index }}),checkAllDns" />
                                </button>
                                <button type="button" wire:click="startEdit({{ $index }})"
                                    class="icon-button shrink-0" title="Edit domain" aria-label="Edit domain">
                                    <x-reicon name="settings" class="size-3.5" />
                                </button>
                                <x-modal-confirmation class="!w-auto shrink-0" title="Remove domain?"
                                    buttonTitle="Remove" isErrorButton
                                    submitAction="removeDomainByKey({{ $domainKey }})"
                                    :actions="[
                                        'This domain will be removed from the preview deployment.',
                                        'Redeploy the preview to apply proxy changes.',
                                    ]"
                                    :confirmWithPassword="false" :confirmWithText="false"
                                    step2ButtonText="Remove domain">
                                    <x-slot:trigger>
                                        <button type="button"
                                            class="icon-button shrink-0 text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300"
                                            title="Remove domain" aria-label="Remove domain">
                                            <x-reicon name="trash" class="size-3.5" />
                                        </button>
                                    </x-slot:trigger>
                                </x-modal-confirmation>
                            @endcan
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <template x-teleport="body">
        <div x-show="editOpen" x-cloak class="fixed inset-0 z-99 overflow-y-auto">
            <div class="absolute inset-0 bg-black/50 backdrop-blur-[2px]" @click="editOpen = false"></div>
            <div class="relative flex min-h-full items-center justify-center p-4">
                <div x-show="editOpen" x-trap.inert.noscroll="editOpen"
                    class="application-settings-form application-settings-section relative w-full max-w-3xl">
                    <header>
                        <h3>Edit domain</h3>
                        <button type="button" @click="editOpen = false" class="icon-button" aria-label="Close">
                            <x-reicon name="x" class="size-4" />
                        </button>
                    </header>
                    <div class="application-settings-section-body">
                        <form wire:submit="updateDomain" class="flex flex-col gap-4">
                            <x-forms.domain-input id="editingDomainParts" />
                            <div class="flex justify-end">
                                <x-forms.button type="submit" isHighlighted>Save</x-forms.button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </template>

    @if ($showPortWarningModal)
        <div x-data="{ modalOpen: true }"
            @keydown.escape.window="modalOpen = false; $wire.call('cancelUseUnknownPort')"
            class="relative z-40">
            <template x-teleport="body">
                <div x-show="modalOpen"
                    class="fixed inset-0 z-99 flex min-h-full items-center justify-center overflow-y-auto p-4" x-cloak>
                    <div class="absolute inset-0 bg-black/50 backdrop-blur-[2px]"></div>
                    <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen"
                        class="application-settings-form application-settings-section relative w-full lg:min-w-[36rem] lg:max-w-2xl"
                        style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                        <header>
                            <h3>Use a different port?</h3>
                            <button type="button"
                                @click="modalOpen = false; $wire.call('cancelUseUnknownPort')"
                                class="icon-button" aria-label="Close">
                                <x-reicon name="x" class="size-4" />
                            </button>
                        </header>
                        <div class="application-settings-section-body">
                            <x-callout type="warning" title="Unrecognized internal port" class="mb-4">
                                Port <strong>{{ $unrecognizedPort }}</strong> is not listed in Ports Exposes
                                and is not used by any application domain. The proxy will still route to it,
                                but the container may not be listening there.
                            </x-callout>

                            <div class="mt-4 flex flex-wrap justify-end gap-2 border-t border-neutral-200 pt-4 dark:border-white/[0.08]">
                                <x-forms.button type="button" canGate="update" :canResource="$preview->application"
                                    @click="modalOpen = false; $wire.call('cancelUseUnknownPort')">
                                    Cancel
                                </x-forms.button>
                                <x-forms.button type="button" wire:click="confirmUseUnknownPort" canGate="update"
                                    :canResource="$preview->application"
                                    @click="modalOpen = false" isError>
                                    Use this port anyway
                                </x-forms.button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    @endif
</div>
