@php
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
@endphp

<div wire:key="domain-row-{{ $index }}-{{ md5(($isSuggested ? 's:' : '') . $row['url'] . '|' . ($row['service'] ?? '')) }}"
    @class([
        'flex flex-col gap-3 p-4 border rounded-sm dark:border-coolgray-300',
        'border-dashed border-warning/40 dark:border-warning/30' => $isSuggested,
    ])>
    <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div class="min-w-0 space-y-2">
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ getFqdnWithoutPort($row['url']) }}" target="_blank"
                    class="font-mono text-sm break-all underline dark:text-white">
                    {{ $row['url'] }}
                </a>
                <x-status-badge :status="$dnsLabel" :type="$dnsType"
                    :title="$row['dns_status'] === 'ok' ? null : $row['dns_message']" />
                @if ($isSuggested && ! empty($row['suggestion_label']))
                    <x-status-badge :status="$row['suggestion_label']" type="warning" />
                @endif
            </div>
            @if ($row['dns_status'] !== 'ok' && (filled($row['dns_message']) || ! empty($row['checked_at'])))
                <div class="text-xs dark:text-neutral-400">
                    {{ $row['dns_message'] }}
                    @if (! empty($row['checked_at']))
                        <span class="opacity-70">· Last checked
                            {{ \Illuminate\Support\Carbon::parse($row['checked_at'])->diffForHumans() }}</span>
                    @endif
                </div>
            @endif
        </div>
        <div class="flex flex-wrap items-center gap-2 shrink-0">
            <x-forms.button wire:click="checkDomainDns({{ $index }})">
                Check DNS
            </x-forms.button>
            @can('update', $application)
                @unless ($isReadonlyLabels)
                    @if ($isSuggested)
                        @if ($row['needs_force_add'] ?? false)
                            <x-forms.button wire:click="addSuggestedDomain({{ $index }})" isError>
                                Continue
                            </x-forms.button>
                        @else
                            <x-forms.button wire:click="addSuggestedDomain({{ $index }})" isHighlighted>
                                Add
                            </x-forms.button>
                        @endif
                    @else
                        <x-forms.button wire:click="startEdit({{ $index }})">Edit</x-forms.button>
                        <x-modal-confirmation class="!w-auto shrink-0" title="Remove domain?" buttonTitle="Remove"
                            isErrorButton submitAction="removeDomain({{ $index }})" :actions="[
                                'This domain will be removed from the application.',
                                'Redeploy or restart may be required for proxy changes.',
                            ]" :confirmWithPassword="false" :confirmWithText="false" step2ButtonText="Remove domain" />
                    @endif
                @endunless
            @endcan
        </div>
    </div>
</div>
