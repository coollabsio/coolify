@php
    $showServiceColumn = $showServiceColumn ?? false;
    $showHeader = $showHeader ?? true;
    $gridClass = $showServiceColumn ? 'domains-table-grid-compose' : 'domains-table-grid';
@endphp

<div class="data-table w-full">
    @if ($showHeader)
        <div class="data-table-header {{ $gridClass }}">
            <span>Domain</span>
            @if ($showServiceColumn)
                <span>Service</span>
            @endif
            <span>DNS</span>
            <span>Last checked</span>
            <span></span>
        </div>
    @endif
    @foreach ($rows as $row)
        @php
            $index = collect($domainRows)->search(
                fn ($item) => $item['url'] === $row['url']
                    && (int) $item['service_application_id'] === (int) $row['service_application_id']
                    && (bool) ($item['is_suggested'] ?? false) === (bool) ($row['is_suggested'] ?? false),
            );
            $isSuggested = (bool) ($row['is_suggested'] ?? false);
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
                'pending' => 'DNS pending',
                default => 'DNS unknown',
            };
            $checkedAt = ! empty($row['checked_at'])
                ? \Illuminate\Support\Carbon::parse($row['checked_at'])->diffForHumans()
                : null;
            $serviceLabel = filled($row['service_name'] ?? null)
                ? \Illuminate\Support\Str::headline($row['service_name'])
                : '-';
        @endphp

        <div wire:key="svc-domain-{{ $row['service_application_id'] ?? 'x' }}-{{ $index }}-{{ md5(($isSuggested ? 's:' : '') . $row['url']) }}"
            class="env-table-item">
            <div @class([
                'data-table-row',
                $gridClass,
                'domains-row-suggested' => $isSuggested,
            ])>
                <div class="flex min-w-0 flex-col gap-1">
                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                        @if ($isSuggested)
                            <span
                                class="min-w-0 text-[13px] text-black sm:truncate dark:text-white"
                                title="{{ $row['url'] }} (not configured yet)">
                                {{ $row['url'] }}
                            </span>
                        @else
                            <a href="{{ getFqdnWithoutPort($row['url']) }}" target="_blank"
                                class="min-w-0 text-[13px] text-black underline decoration-neutral-300 underline-offset-2 hover:decoration-coollabs sm:truncate dark:text-fg dark:decoration-white/20 dark:hover:decoration-warning"
                                title="{{ $row['url'] }}">
                                {{ $row['url'] }}
                            </a>
                        @endif
                        @if ($isSuggested && ! empty($row['suggestion_label']))
                            <span class="table-badge table-badge-warning shrink-0">{{ $row['suggestion_label'] }}</span>
                        @endif
                    </div>
                    @if ($isSuggested && filled($row['dns_message']))
                        <p class="text-[12px] leading-4 text-amber-700 sm:truncate dark:text-amber-400/90"
                            title="{{ $row['dns_message'] }}">
                            {{ $row['dns_message'] }}
                        </p>
                    @endif
                </div>

                @if ($showServiceColumn)
                    <div class="domains-service-desktop min-w-0 truncate text-[13px] text-neutral-500 dark:text-fg-dim"
                        title="{{ $serviceLabel }}">
                        {{ $serviceLabel }}
                    </div>
                @endif

                <div class="flex min-w-0 items-center">
                    @if ($row['dns_status'] === 'failed')
                        <x-status-badge as="button" @click="$dispatch('open-dns-records-modal')" :status="$dnsLabel" :type="$dnsType"
                            title="View DNS records to fix" class="cursor-pointer hover:bg-neutral-200 dark:hover:bg-white/[0.1]" />
                    @else
                        <x-status-badge :status="$dnsLabel" :type="$dnsType"
                            :title="$row['dns_status'] === 'ok' ? null : $row['dns_message']" />
                    @endif
                </div>

                <div class="min-w-0 truncate text-[13px] text-neutral-500 dark:text-fg-dim">
                    {{ $checkedAt ?: '-' }}
                </div>

                <div class="flex items-center justify-end gap-1">
                    @can('update', $service)
                        <button type="button" wire:click="checkDomainDns({{ $index }})"
                            wire:loading.attr="disabled"
                            wire:target="checkDomainDns({{ $index }}),checkAllDns"
                            class="icon-button shrink-0" title="Check DNS" aria-label="Check DNS">
                            <x-reicon name="refresh" class="size-3.5"
                                wire:loading.remove.delay
                                wire:target="checkDomainDns({{ $index }}),checkAllDns" />
                            <x-loading-on-button wire:loading.delay
                                wire:target="checkDomainDns({{ $index }}),checkAllDns" />
                        </button>
                        @if ($isSuggested)
                            @if ($row['needs_force_add'] ?? false)
                                <x-forms.button canGate="update" :canResource="$service"
                                    wire:click="addSuggestedDomain({{ $index }})" isError
                                    class="h-7! px-2! text-[12px]!">
                                    Continue
                                </x-forms.button>
                            @else
                                <x-forms.button canGate="update" :canResource="$service"
                                    wire:click="addSuggestedDomain({{ $index }})" isHighlighted
                                    class="h-7! shrink-0 px-2.5! text-[12px]!">
                                    Add domain
                                </x-forms.button>
                            @endif
                        @else
                            <button type="button"
                                @click="$dispatch('open-edit-domain', {
                                    index: {{ $index }},
                                    url: @js($row['url']),
                                    serviceApplicationId: {{ (int) ($row['service_application_id'] ?? 0) }},
                                    serviceLabel: @js($serviceLabel),
                                })"
                                class="icon-button shrink-0" title="Edit domain" aria-label="Edit domain">
                                <x-reicon name="settings" class="size-3.5" />
                            </button>
                            <x-modal-confirmation class="!w-auto shrink-0" title="Remove domain?"
                                buttonTitle="Remove" isErrorButton
                                submitAction="removeDomain({{ $index }})" :actions="[
                                    'This domain will be removed from the service application.',
                                    'Redeploy or restart may be required for proxy changes.',
                                ]" :confirmWithPassword="false" :confirmWithText="false"
                                step2ButtonText="Remove domain">
                                <x-slot:trigger>
                                    <button type="button"
                                        class="icon-button shrink-0 text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300"
                                        title="Remove domain" aria-label="Remove domain">
                                        <x-reicon name="trash" class="size-3.5" />
                                    </button>
                                </x-slot:trigger>
                            </x-modal-confirmation>
                        @endif
                    @endcan
                </div>
            </div>
        </div>
    @endforeach
</div>
