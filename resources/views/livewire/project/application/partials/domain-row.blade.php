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
        'checking' => 'Checking DNS...',
        'pending' => 'DNS pending',
        default => 'DNS unknown',
    };
    $gridClass = $domainGridClass ?? (($isCompose ?? false) ? 'domains-table-grid-compose' : 'domains-table-grid');
    $domainParts = $isSuggested ? null : parse_url($row['url']);
    $faviconUrl = is_array($domainParts) && isset($domainParts['scheme'], $domainParts['host'])
        ? $domainParts['scheme'].'://'.$domainParts['host'].(isset($domainParts['port']) ? ':'.$domainParts['port'] : '').'/favicon.ico'
        : null;
    $redirectPairKey = function (string $url): string {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return $url;
        }

        $host = preg_replace('/^www\./i', '', $parts['host']);

        return strtolower(($parts['scheme'] ?? '').'://'.$host.':'.($parts['port'] ?? '').($parts['path'] ?? ''));
    };
    $pairKey = $redirectPairKey($row['url']);
    $firstPairRowIndex = collect($domainRows)
        ->reject(fn ($item) => (bool) ($item['is_suggested'] ?? false))
        ->filter(fn ($item) => ($item['service'] ?? null) === ($row['service'] ?? null))
        ->filter(fn ($item) => $redirectPairKey($item['url']) === $pairKey)
        ->keys()
        ->first();
    $showDirection = ($showDirectionControl ?? true) && ! $isSuggested && $firstPairRowIndex === $index;
@endphp

<div wire:key="domain-row-{{ $index }}-{{ md5(($isSuggested ? 's:' : '') . $row['url'] . '|' . ($row['service'] ?? '')) }}"
    class="env-table-item">
    <div @class([
        'data-table-row',
        $gridClass,
        'domains-row-suggested' => $isSuggested,
        'domains-row-without-direction' => ! $showDirection,
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
                    @if ($faviconUrl)
                        <span class="relative size-4 shrink-0" aria-hidden="true">
                            <x-reicon name="globe"
                                class="domain-favicon-fallback size-4 text-neutral-400 dark:text-fg-faint" />
                            <img src="{{ $faviconUrl }}" alt="" loading="lazy" decoding="async"
                                referrerpolicy="no-referrer"
                                x-init="if ($el.complete && $el.naturalWidth > 0) { $el.previousElementSibling.classList.add('hidden'); $el.classList.remove('invisible') }"
                                x-on:load="$el.previousElementSibling.classList.add('hidden'); $el.classList.remove('invisible')"
                                x-on:error="$el.remove()"
                                class="invisible absolute inset-0 size-4 rounded-sm" />
                        </span>
                    @endif
                    <a href="{{ getFqdnWithoutPort($row['url']) }}" target="_blank"
                        class="min-w-0 flex-1 text-[13px] text-black underline decoration-neutral-300 underline-offset-2 hover:decoration-coollabs sm:truncate dark:text-fg dark:decoration-white/20 dark:hover:decoration-warning"
                        title="{{ $row['url'] }}">
                        {{ $row['url'] }}
                    </a>
                @endif
                @if ($isSuggested && ! empty($row['suggestion_label']))
                    <span class="table-badge table-badge-warning shrink-0">{{ $row['suggestion_label'] }}</span>
                @endif
                @if ($isCompose ?? false)
                    <span class="domains-service-mobile table-badge shrink-0">{{ $row['service'] ?? '-' }}</span>
                @endif
            </div>
            @if ($isSuggested && filled($row['dns_message']))
                <p class="text-[12px] leading-4 text-amber-700 sm:truncate dark:text-amber-400/90"
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
            @if ($row['dns_status'] === 'failed')
                <x-status-badge as="button" @click="$dispatch('open-dns-records-modal')" :status="$dnsLabel" :type="$dnsType"
                    title="View DNS records to fix" class="cursor-pointer hover:bg-neutral-200 dark:hover:bg-white/[0.1]" />
            @else
                <x-status-badge :status="$dnsLabel" :type="$dnsType"
                    :title="$row['dns_status'] === 'ok' ? null : $row['dns_message']" />
            @endif
        </div>

        <div class="min-w-0" title="Search engine indexing">
            @unless ($isSuggested)
                <span class="domains-mobile-label">Search engine indexing</span>
            @endunless
            @if ($isSuggested)
                <span class="text-[13px] text-neutral-500 dark:text-fg-dim">-</span>
            @elseif (auth()->user()?->can('update', $application) && ! $labelsAreWritable)
                <x-forms.listbox id="domain-indexing-{{ $index }}" :wire="false"
                    preserveValue
                    :value="$application->isDomainNoindexed($row['url']) ? 'noindex' : 'index'"
                    onChange="toggleNoindexDomain" :onChangeArgs="[$row['url']]" portal :options="[
                        ['value' => 'index', 'label' => 'Indexable'],
                        ['value' => 'noindex', 'label' => 'Noindex'],
                    ]" />
            @else
                <span class="text-[13px] text-neutral-500 dark:text-fg-dim">
                    {{ $application->isDomainNoindexed($row['url']) ? 'Noindex' : 'Indexable' }}
                </span>
            @endif
        </div>

        @if ($showDirectionControl ?? true)
        <div class="min-w-0" title="Direction">
            @php
                $rowDirection = $domainDirection ?? $redirect;
                $directionLabel = match ($rowDirection) {
                    'www' => 'Redirect to www',
                    'non-www' => 'Redirect to non-www',
                    default => 'Allow both',
                };
            @endphp
            @if ($showDirection)
                <span class="domains-mobile-label">Direction</span>
            @endif
            @if ($showDirection && auth()->user()?->can('update', $application) && ! $labelsAreWritable)
                <x-forms.listbox id="domain-direction-{{ $index }}" :wire="false" :value="$rowDirection"
                    preserveValue
                    :onChange="$isCompose ? 'updateServiceRedirect' : 'updateRedirect'"
                    :onChangeArgs="$isCompose ? [$row['service']] : []" portal :options="[
                        ['value' => 'both', 'label' => 'Allow www & non-www'],
                        ['value' => 'www', 'label' => 'Redirect to www'],
                        ['value' => 'non-www', 'label' => 'Redirect to non-www'],
                    ]" />
            @elseif ($showDirection)
                <span class="text-[13px] text-neutral-500 dark:text-fg-dim">{{ $directionLabel }}</span>
            @endif
        </div>
        @endif

        <div class="flex items-center justify-end gap-1">
            @can('update', $application)
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
                @unless ($labelsAreWritable)
                    @if ($isSuggested)
                        @if ($row['needs_force_add'] ?? false)
                            <x-forms.button wire:click="addSuggestedDomain({{ $index }})" isError class="h-7! px-2! text-[12px]!">
                                Continue
                            </x-forms.button>
                        @else
                            <x-forms.button wire:click="addSuggestedDomain({{ $index }})" isHighlighted class="h-7! shrink-0 px-2.5! text-[12px]!">
                                Add domain
                            </x-forms.button>
                        @endif
                    @else
                        <button type="button" wire:click="startEdit({{ $index }})"
                            class="icon-button shrink-0"
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
