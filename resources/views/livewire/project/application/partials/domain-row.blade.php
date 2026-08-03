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
    $checkedAt = ! empty($row['checked_at'])
        ? \Illuminate\Support\Carbon::parse($row['checked_at'])->diffForHumans()
        : null;
    $gridClass = ($isCompose ?? false) ? 'domains-table-grid-compose' : 'domains-table-grid';
@endphp

<div wire:key="domain-row-{{ $index }}-{{ md5(($isSuggested ? 's:' : '') . $row['url'] . '|' . ($row['service'] ?? '')) }}"
    class="env-table-item">
    <div @class([
        'data-table-row',
        $gridClass,
        'opacity-90' => $isSuggested,
    ])>
        <div class="flex min-w-0 flex-col gap-1">
            <div class="flex min-w-0 flex-wrap items-center gap-2">
                <a href="{{ getFqdnWithoutPort($row['url']) }}" target="_blank"
                    class="min-w-0 font-mono text-[13px] text-black underline decoration-neutral-300 underline-offset-2 hover:decoration-coollabs sm:truncate dark:text-fg dark:decoration-white/20 dark:hover:decoration-warning"
                    title="{{ $row['url'] }}">
                    {{ $row['url'] }}
                </a>
                @if ($isSuggested && ! empty($row['suggestion_label']))
                    <span class="table-badge shrink-0">{{ $row['suggestion_label'] }}</span>
                @endif
                @if ($isCompose ?? false)
                    <span class="domains-service-mobile table-badge shrink-0">{{ $row['service'] ?? '-' }}</span>
                @endif
            </div>
            @if ($row['dns_status'] !== 'ok' && filled($row['dns_message']))
                <p class="text-[12px] leading-4 text-neutral-500 sm:truncate dark:text-fg-dim"
                    title="{{ $row['dns_message'] }}">
                    {{ $row['dns_message'] }}
                </p>
            @endif
        </div>

        @if ($isCompose ?? false)
            <div class="domains-service-desktop min-w-0 truncate text-[13px] text-neutral-500 dark:text-fg-dim"
                title="{{ $row['service'] ?? '' }}">
                {{ $row['service'] ?? '-' }}
            </div>
        @endif

        <div class="flex min-w-0 items-center">
            <x-status-badge :status="$dnsLabel" :type="$dnsType"
                :title="$row['dns_status'] === 'ok' ? null : $row['dns_message']" />
        </div>

        <div class="min-w-0 truncate text-[13px] text-neutral-500 dark:text-fg-dim"
            title="{{ $checkedAt ?? '' }}">
            {{ $checkedAt ?: '-' }}
        </div>

        <div class="flex items-center justify-end gap-1">
            @can('update', $application)
                <button type="button" wire:click="checkDomainDns({{ $index }})" class="icon-button shrink-0"
                    title="Check DNS" aria-label="Check DNS">
                    <x-reicon name="refresh" class="size-3.5" />
                </button>
                @unless ($labelsAreWritable)
                    @if ($isSuggested)
                        @if ($row['needs_force_add'] ?? false)
                            <x-forms.button wire:click="addSuggestedDomain({{ $index }})" isError class="h-7! px-2! text-[12px]!">
                                Continue
                            </x-forms.button>
                        @else
                            <x-forms.button wire:click="addSuggestedDomain({{ $index }})" isHighlighted class="h-7! px-2! text-[12px]!">
                                Add
                            </x-forms.button>
                        @endif
                    @else
                        <button type="button" wire:click="startEdit({{ $index }})" class="icon-button shrink-0"
                            title="Edit domain" aria-label="Edit domain">
                            <x-reicon name="settings" class="size-3.5" />
                        </button>
                        <x-modal-confirmation class="!w-auto shrink-0" title="Remove domain?" buttonTitle="Remove"
                            isErrorButton submitAction="removeDomain({{ $index }})" :actions="[
                                'This domain will be removed from the application.',
                                'Redeploy or restart may be required for proxy changes.',
                            ]" :confirmWithPassword="false" :confirmWithText="false" step2ButtonText="Remove domain">
                            <x-slot:trigger>
                                <button type="button" class="icon-button shrink-0 text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300"
                                    title="Remove domain" aria-label="Remove domain">
                                    <x-reicon name="trash" class="size-3.5" />
                                </button>
                            </x-slot:trigger>
                        </x-modal-confirmation>
                    @endif
                @endunless
            @endcan
        </div>
    </div>
</div>
