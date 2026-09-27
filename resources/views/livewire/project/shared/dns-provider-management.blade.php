<div class="contents">
    @php
        $dnsAuthResource = property_exists($this, 'application') ? $this->application : $this->service;
    @endphp
    @if ($showDnsProviderModal)
    <div x-data="{ modalOpen: @entangle('showDnsProviderModal') }" class="relative h-auto w-auto"
        :class="{ 'z-40': modalOpen }" @keydown.escape.window="modalOpen = false; $wire.closeDnsProviderModal()">
        <template x-teleport="body">
            <div x-show="modalOpen" class="fixed inset-0 z-99 overflow-y-auto" x-cloak>
                <div class="absolute inset-0 bg-black/50 backdrop-blur-[2px]" @click="modalOpen = false; $wire.closeDnsProviderModal()"></div>
                <div class="relative flex min-h-full items-start justify-center p-4 sm:items-center">
                    <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen"
                        class="application-settings-form application-settings-section relative flex w-full max-w-2xl flex-col overflow-hidden">
                        <header class="flex-nowrap!"><h3 class="min-w-0 flex-1 truncate">Configure DNS</h3>
                            <button type="button" wire:click="closeDnsProviderModal" class="icon-button" aria-label="Close"><x-reicon name="x" class="size-4" /></button>
                        </header>
                        <div class="application-settings-section-body flex flex-col gap-3">
                            <p class="text-sm text-neutral-600 dark:text-fg-dim">Coolify matched each hostname to a zone available through your connected DNS credentials.</p>
                            @foreach ($dnsProviderProposals as $proposal)
                                @php($key = $proposal['hostname'].'|'.$proposal['zone_id'])
                                <div wire:key="dns-proposal-{{ $key }}" class="flex flex-col gap-3 rounded-lg border border-neutral-200 p-3 dark:border-white/[0.08] sm:flex-row sm:items-center sm:justify-between">
                                    <div class="min-w-0 text-sm"><div class="truncate font-medium text-black dark:text-fg">{{ $proposal['hostname'] }}</div>
                                        <div class="text-xs text-neutral-500 dark:text-fg-dim">{{ $proposal['credential'] }} · {{ $proposal['zone'] }} · {{ $proposal['target'] }}</div>
                                    </div>
                                    @if ($proposal['managed'])
                                        <x-status-badge status="Managed by Coolify" type="success" />
                                    @elseif (isset($dnsProviderConflicts[$key]))
                                        @php($conflict = $dnsProviderConflicts[$key])
                                        <div class="flex flex-col items-end gap-2 text-xs"><span class="text-red-600 dark:text-red-400">Currently {{ $conflict['current'] }}</span>
                                            <x-modal-confirmation title="Replace conflicting DNS record?" isErrorButton buttonTitle="Replace record"
                                                submitAction="replaceManagedDnsRecord({{ \Illuminate\Support\Js::from($proposal['hostname']) }}, {{ $proposal['zone_id'] }})"
                                                :actions="['Replace '.$proposal['hostname'].' value '.$conflict['current'].' with '.$conflict['proposed'], 'Coolify will manage the replaced record.']"
                                                :confirmWithPassword="false" :confirmWithText="false" step2ButtonText="Replace record"
                                                canGate="update" :canResource="$dnsAuthResource" />
                                        </div>
                                    @else
                                        <x-forms.button type="button"
                                            wire:click="createManagedDnsRecord({{ \Illuminate\Support\Js::from($proposal['hostname']) }}, {{ $proposal['zone_id'] }})"
                                            wire:target="createManagedDnsRecord" isHighlighted
                                            canGate="update" :canResource="$dnsAuthResource">Create DNS record</x-forms.button>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
    @endif
</div>
