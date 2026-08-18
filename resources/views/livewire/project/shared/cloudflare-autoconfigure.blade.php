{{-- DNS entries: Domain Connect (Cloud only + key) and/or generic Type/Name/Value records. --}}
@php
    $domainConnectAvailable = $this->domainConnectAvailable();
@endphp

<div x-data="{ dnsEntriesOpen: false }" class="relative" @click.outside="dnsEntriesOpen = false">
    <button type="button" class="button" @click="dnsEntriesOpen = !dnsEntriesOpen" aria-haspopup="menu"
        x-bind:aria-expanded="dnsEntriesOpen" title="DNS entries for this server">
        <x-reicon name="globe" class="size-3.5" />
        DNS entries
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
            stroke="currentColor" class="size-3.5 shrink-0 opacity-60">
            <path stroke-linecap="round" stroke-linejoin="round" d="m8 9 4-4 4 4m0 6-4 4-4-4" />
        </svg>
    </button>
    <div x-show="dnsEntriesOpen" x-cloak role="menu" x-transition.origin.top.right
        class="listbox-panel left-auto! right-0! z-[90]! w-56! min-w-56!">
        @if ($domainConnectAvailable)
            <button type="button" class="listbox-option justify-start! gap-2.5!" role="menuitem"
                wire:click="openCloudflareAutoconfigureModal" @click="dnsEntriesOpen = false">
                <x-reicon name="globe" class="size-3.5 shrink-0 opacity-70" />
                Cloudflare
            </button>
        @endif
        <button type="button" class="listbox-option justify-start! gap-2.5!" role="menuitem"
            @click="dnsEntriesOpen = false; $dispatch('open-dns-records-modal')">
            <x-reicon name="documentation" class="size-3.5 shrink-0 opacity-70" />
            Manual records
        </button>
    </div>
</div>

@if ($showCloudflareAutoconfigureModal && $domainConnectAvailable)
    <div x-data="{ modalOpen: @entangle('showCloudflareAutoconfigureModal') }"
        @keydown.escape.window="modalOpen = false; $wire.closeCloudflareAutoconfigureModal()"
        class="relative h-auto w-auto" :class="{ 'z-40': modalOpen }">
        <template x-teleport="body">
            <div x-show="modalOpen" class="fixed inset-0 z-99 overflow-y-auto" x-cloak>
                <div x-show="modalOpen" x-transition:enter="ease-out duration-100"
                    x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    class="absolute inset-0 h-full w-full bg-black/50 backdrop-blur-[2px]"
                    @click="modalOpen = false; $wire.closeCloudflareAutoconfigureModal()"></div>
                <div class="relative flex min-h-full items-start justify-center p-4 sm:items-center">
                    <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen"
                        x-transition:enter="ease-out duration-100"
                        x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave="ease-in duration-100"
                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                        class="application-settings-form application-settings-section relative flex w-full max-w-lg flex-col overflow-hidden"
                        style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                        <header class="flex-nowrap!">
                            <h3 class="min-w-0 flex-1 truncate">Configure DNS on Cloudflare</h3>
                            <button type="button" wire:click="closeCloudflareAutoconfigureModal"
                                class="icon-button shrink-0" aria-label="Close">
                                <x-reicon name="x" class="size-4" />
                            </button>
                        </header>
                        <div class="application-settings-section-body flex flex-col gap-4">
                            @php
                                $cloudflareHosts = $this->allDomainHostnames();
                            @endphp
                            <p class="text-sm leading-6 text-neutral-600 dark:text-fg-dim">
                                Opens Cloudflare Domain Connect for every domain on this resource, with A records
                                prefilled to this server’s IP. Authorize each change in Cloudflare.
                            </p>

                            <div>
                                <p class="mb-1.5 text-sm font-medium text-black dark:text-white">Domains</p>
                                <ul
                                    class="max-h-40 space-y-1 overflow-y-auto rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 font-mono text-[13px] text-black dark:border-coolgray-300 dark:bg-coolgray-100 dark:text-fg">
                                    @foreach ($cloudflareHosts as $host)
                                        <li>{{ $host }}</li>
                                    @endforeach
                                </ul>
                            </div>

                            <x-forms.input label="Server IP (A record target)"
                                value="{{ $serverIp ?: 'Unavailable' }}" readonly
                                helper="This IP is taken from the destination server." />

                            <div class="flex flex-wrap items-center justify-end gap-2 pt-2">
                                <x-forms.button type="button" wire:click="closeCloudflareAutoconfigureModal">
                                    Cancel
                                </x-forms.button>
                                <x-forms.button type="button" wire:click="applyCloudflareAutoconfigure"
                                    isHighlighted>
                                    Open Cloudflare
                                    <x-external-link class="size-3 opacity-70" />
                                </x-forms.button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
@endif

{{-- Always mounted so open/close is Alpine-only (no Livewire round-trip). --}}
@php
    $dnsHints = $this->dnsRecordHints();
    $dnsCopyText = $this->dnsRecordsCopyText();
@endphp
<div
    x-data="{
        modalOpen: false,
        openDnsRecords() {
            this.modalOpen = true;
        },
        closeDnsRecords() {
            this.modalOpen = false;
        },
        async recheckDns() {
            this.modalOpen = true;
            try {
                await $wire.recheckDnsRecordsInModal();
            } finally {
                // Re-assert open after Livewire morph may re-init Alpine.
                this.modalOpen = true;
            }
        },
    }"
    @open-dns-records-modal.window="openDnsRecords()"
    class="relative h-auto w-auto" :class="{ 'z-40': modalOpen }"
    @keydown.window.escape="if (modalOpen) { closeDnsRecords() }">
    <template x-teleport="body">
        <div x-show="modalOpen" class="fixed inset-0 z-99 overflow-y-auto" x-cloak>
            <div x-show="modalOpen" x-transition:enter="ease-out duration-100"
                x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                class="absolute inset-0 h-full w-full bg-black/50 backdrop-blur-[2px]"
                @click="closeDnsRecords()"></div>
            <div class="relative flex min-h-full items-start justify-center p-4 sm:items-center">
                <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen"
                    x-transition:enter="ease-out duration-100"
                    x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                    x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave="ease-in duration-100"
                    x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                    x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                    class="application-settings-form application-settings-section relative flex w-full max-w-2xl flex-col overflow-hidden"
                    style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                    <header class="flex-nowrap!">
                        <h3 class="min-w-0 flex-1 truncate">DNS entries</h3>
                        <button type="button" @click="closeDnsRecords()"
                            class="icon-button shrink-0" aria-label="Close">
                            <x-reicon name="x" class="size-4" />
                        </button>
                    </header>
                    <div class="application-settings-section-body flex flex-col gap-4">
                        <p class="text-sm leading-6 text-neutral-600 dark:text-fg-dim">
                            Hosts that still need DNS at your provider (working domains are omitted). Create matching
                            Type / Name / Value records so traffic reaches this server.
                        </p>

                        @if (blank($serverIp) && count($dnsHints) === 0)
                            <x-callout type="warning" title="No server IP">
                                Could not determine a public IP for this destination. Set the server IP (or instance public IPv4 for localhost) first.
                            </x-callout>
                        @elseif (count($dnsHints) === 0)
                            <x-callout type="info" title="Nothing to configure">
                                No pending DNS entries. All listed domains already resolve correctly, or no domains are configured yet.
                                Use Recheck after changing DNS.
                            </x-callout>
                        @else
                            <div class="overflow-x-auto rounded-md border border-neutral-200 dark:border-coolgray-300">
                                <table class="w-full min-w-[32rem] text-left text-sm">
                                    <thead
                                        class="bg-neutral-50 text-[12px] uppercase tracking-wide text-neutral-500 dark:bg-coolgray-100 dark:text-fg-dim">
                                        <tr>
                                            <th class="px-3 py-2 font-medium">Type</th>
                                            <th class="px-3 py-2 font-medium">Name</th>
                                            <th class="px-3 py-2 font-medium">Value</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-neutral-200 dark:divide-coolgray-300">
                                        @foreach ($dnsHints as $record)
                                            <tr class="text-[13px] text-black dark:text-fg">
                                                <td class="px-3 py-2.5">{{ $record['type'] }}</td>
                                                <td class="px-3 py-2.5">
                                                    @include('livewire.project.shared.partials.dns-copy-cell', [
                                                        'text' => $record['name'],
                                                        'label' => 'Copy name',
                                                        'break' => true,
                                                    ])
                                                </td>
                                                <td class="px-3 py-2.5">
                                                    @include('livewire.project.shared.partials.dns-copy-cell', [
                                                        'text' => $record['value'],
                                                        'label' => 'Copy value',
                                                        'break' => true,
                                                    ])
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            @if (filled($dnsCopyText))
                                <div class="flex flex-wrap items-center justify-between gap-2"
                                    x-data="{
                                        copied: false,
                                        async copyAll(text) {
                                            try {
                                                if (navigator.clipboard?.writeText) {
                                                    await navigator.clipboard.writeText(text);
                                                } else {
                                                    const el = document.createElement('textarea');
                                                    el.value = text;
                                                    el.setAttribute('readonly', '');
                                                    el.style.position = 'fixed';
                                                    el.style.left = '-9999px';
                                                    document.body.appendChild(el);
                                                    el.select();
                                                    document.execCommand('copy');
                                                    document.body.removeChild(el);
                                                }
                                                this.copied = true;
                                                setTimeout(() => this.copied = false, 1000);
                                            } catch (e) {
                                                console.error('Copy failed', e);
                                            }
                                        }
                                    }">
                                    <p class="text-[12px] text-neutral-500 dark:text-fg-dim">
                                        {{ count($dnsHints) }}
                                        {{ count($dnsHints) === 1 ? 'entry' : 'entries' }}
                                        · BIND zone format
                                    </p>
                                    <button type="button" class="button shrink-0"
                                        title="Copy as BIND-compatible zone file"
                                        @click.prevent="copyAll(@js($dnsCopyText))">
                                        <span x-text="copied ? 'Copied' : 'Copy all'"></span>
                                    </button>
                                </div>
                            @endif
                        @endif

                        <div class="flex flex-wrap items-center justify-between gap-2 pt-2">
                            <x-forms.button type="button" @click="recheckDns()"
                                wire:target="recheckDnsRecordsInModal,checkAllDns,checkDomainDns"
                                title="Recheck DNS">
                                <x-reicon name="refresh" class="size-3.5" />
                                Recheck
                            </x-forms.button>
                            <x-forms.button type="button" @click="closeDnsRecords()" isHighlighted>
                                Done
                            </x-forms.button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>
