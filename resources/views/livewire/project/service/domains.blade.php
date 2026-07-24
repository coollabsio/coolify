<div class="flex flex-col gap-4">
    <div>
        <div class="flex flex-wrap items-center gap-2">
            <h2>Domains</h2>
            @can('update', $service)
                @if (count($serviceApps) > 0)
                    <x-modal-input buttonTitle="+ Add" title="Add Domain" isHighlightedButton :wireIgnore="false"
                        :closeOutside="false">
                        <form wire:submit="addDomain" class="flex flex-col gap-4">
                            @if (count($serviceApps) > 0)
                                <x-forms.select label="Service application" id="newServiceApplicationId" required>
                                    @foreach ($serviceApps as $app)
                                        <option value="{{ $app['id'] }}">
                                            {{ $app['name'] }}
                                            @if ($app['image'])
                                                ({{ $app['image'] }})
                                            @endif
                                        </option>
                                    @endforeach
                                </x-forms.select>
                            @endif

                            @php
                                $selectedApp = collect($serviceApps)->firstWhere('id', $newServiceApplicationId);
                                $selectedRequiredPort = $selectedApp['required_port'] ?? null;
                            @endphp
                            @if ($selectedRequiredPort)
                                <x-callout type="info" title="Required Port: {{ $selectedRequiredPort }}">
                                    This service requires port <strong>{{ $selectedRequiredPort }}</strong> in domain
                                    URLs.
                                    Example:
                                    <span class="font-mono">https://app.example.com:{{ $selectedRequiredPort }}</span>
                                </x-callout>
                            @endif

                            <x-forms.input id="newDomain" label="Domain URL" placeholder="https://app.example.com"
                                helper="Full URL including scheme. Optional path and container port are supported."
                                required />

                            @if ($addDomainDnsFailed)
                                <x-callout type="danger" title="DNS validation failed">
                                    {{ $addDomainDnsMessage }}
                                    @if ($serverIp)
                                        <div class="pt-2 text-sm">
                                            Expected target:
                                            <span class="font-mono">{{ $this->dnsTargetLabel() ?? $serverIp }}</span>
                                        </div>
                                    @endif
                                </x-callout>
                            @endif

                            <div class="flex flex-wrap items-center justify-between gap-2 pt-2">
                                <x-forms.button type="button" wire:click="generateDomain">
                                    Generate Domain
                                </x-forms.button>
                                <div class="flex flex-wrap gap-2">
                                    @if ($addDomainDnsFailed)
                                        <x-forms.button type="button" wire:click="confirmAddDomainDespiteDns" isError>
                                            Continue
                                        </x-forms.button>
                                    @else
                                        <x-forms.button type="submit" isHighlighted>
                                            Save
                                        </x-forms.button>
                                    @endif
                                </div>
                            </div>
                        </form>
                    </x-modal-input>
                @endif
                <x-forms.button wire:click="checkAllDns">
                    Recheck DNS
                </x-forms.button>
            @endcan
        </div>
        <div class="text-sm dark:text-neutral-400">
            Manage domains for every service application in this stack.
        </div>
    </div>

    @cannot('update', $service)
        <x-callout type="danger" title="Insufficient Permissions">
            You don't have permission to manage domains. Contact your team administrator for access.
        </x-callout>
    @endcannot

    @if (count($serviceApps) === 0)
        <div class="p-6 text-sm border border-dashed rounded-sm dark:border-coolgray-300 dark:text-neutral-400">
            No application services that accept domains. Only database services are available.
        </div>
    @elseif (count($domainRows) === 0)
        <div class="p-6 text-sm border border-dashed rounded-sm dark:border-coolgray-300 dark:text-neutral-400">
            No domains configured yet.
            @can('update', $service)
                Use <strong>+ Add</strong> to assign a domain to a service application.
            @endcan
        </div>
    @else
        @php
            $grouped = collect($domainRows)->groupBy('service_application_id');
        @endphp
        <div class="flex flex-col gap-6">
            @foreach ($grouped as $appId => $rows)
                @php
                    $first = $rows->first();
                    $appMeta = collect($serviceApps)->firstWhere('id', (int) $appId);
                    $heading = $first['service_name'] ?? ($appMeta['name'] ?? 'Service');
                    $image = $first['service_image'] ?? ($appMeta['image'] ?? null);
                    $configuredCount = $rows->where('is_suggested', false)->count();
                    $suggestedCount = $rows->where('is_suggested', true)->count();
                @endphp

                <div class="flex flex-col gap-2">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-base font-semibold">
                            {{ Str::headline($heading) }}
                            @if ($image)
                                <span class="text-xs font-normal dark:text-neutral-400">({{ $image }})</span>
                            @endif
                        </h3>
                        <span class="text-xs dark:text-neutral-400">
                            {{ $configuredCount }} domain(s)
                            @if ($suggestedCount > 0)
                                · {{ $suggestedCount }} suggested
                            @endif
                        </span>
                    </div>

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
                        @endphp

                        <div wire:key="svc-domain-{{ $appId }}-{{ $index }}-{{ md5(($isSuggested ? 's:' : '') . $row['url']) }}"
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
                                    @can('update', $service)
                                        <x-forms.button wire:click="checkDomainDns({{ $index }})">
                                            Check DNS
                                        </x-forms.button>
                                        @if ($isSuggested)
                                            @if ($row['needs_force_add'] ?? false)
                                                <x-forms.button wire:click="addSuggestedDomain({{ $index }})" isError>
                                                    Continue
                                                </x-forms.button>
                                            @else
                                                <x-forms.button wire:click="addSuggestedDomain({{ $index }})"
                                                    isHighlighted>
                                                    Add
                                                </x-forms.button>
                                            @endif
                                        @else
                                            <x-forms.button wire:click="startEdit({{ $index }})">Edit</x-forms.button>
                                            <x-modal-confirmation class="!w-auto shrink-0" title="Remove domain?"
                                                buttonTitle="Remove" isErrorButton
                                                submitAction="removeDomain({{ $index }})" :actions="[
                                                    'This domain will be removed from the service application.',
                                                    'Redeploy or restart may be required for proxy changes.',
                                                ]" :confirmWithPassword="false" :confirmWithText="false"
                                                step2ButtonText="Remove domain" />
                                        @endif
                                    @endcan
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    @endif

    {{-- Edit domain modal --}}
    @if ($showEditDomainModal)
        <div x-data="{ modalOpen: @entangle('showEditDomainModal') }"
            @keydown.escape.window="modalOpen = false; $wire.cancelEdit()"
            class="relative w-auto h-auto" :class="{ 'z-40': modalOpen }">
            <template x-teleport="body">
                <div x-show="modalOpen" class="fixed inset-0 z-99 overflow-y-auto" x-cloak>
                    <div x-show="modalOpen" x-transition:enter="ease-out duration-100"
                        x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                        x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
                        x-transition:leave-end="opacity-0"
                        class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs"
                        @click="modalOpen = false; $wire.cancelEdit()"></div>
                    <div class="relative flex min-h-full items-start justify-center p-4 sm:items-center">
                        <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen"
                            x-transition:enter="ease-out duration-100"
                            x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                            x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                            x-transition:leave="ease-in duration-100"
                            x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                            x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                            class="relative flex max-h-[calc(100dvh-2rem)] w-full flex-col overflow-hidden rounded-sm border border-neutral-200 bg-white drop-shadow-sm dark:border-coolgray-300 dark:bg-base lg:w-auto lg:min-w-2xl lg:max-w-4xl">
                            <div class="flex items-center justify-between py-6 px-6 shrink-0">
                                <h3 class="text-2xl font-bold">Edit Domain</h3>
                                <button type="button" wire:click="cancelEdit"
                                    class="absolute cursor-pointer top-0 right-0 flex items-center justify-center w-8 h-8 mt-5 mr-5 rounded-full dark:text-white hover:bg-neutral-100 dark:hover:bg-coolgray-300">
                                    <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none"
                                        viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                            <div class="relative min-h-0 flex-1 overflow-y-auto px-6 pb-6 pt-1">
                                <form wire:submit="updateDomain" class="flex flex-col gap-4">
                                    @php
                                        $editApp = collect($serviceApps)->firstWhere('id', $editingServiceApplicationId);
                                    @endphp
                                    @if ($editApp)
                                        <x-forms.input label="Service application" value="{{ $editApp['name'] }}"
                                            readonly />
                                    @endif
                                    <x-forms.input id="editingDomain" label="Domain URL"
                                        placeholder="https://app.example.com" required />
                                    @if ($editDomainDnsFailed)
                                        <x-callout type="danger" title="DNS validation failed">
                                            {{ $editDomainDnsMessage }}
                                        </x-callout>
                                    @endif
                                    <div class="flex flex-wrap items-center justify-end gap-2 pt-2">
                                        <x-forms.button type="button" wire:click="cancelEdit"
                                            class="dark:bg-coolgray-200 dark:hover:bg-coolgray-300">
                                            Cancel
                                        </x-forms.button>
                                        @if ($editDomainDnsFailed)
                                            <x-forms.button type="button" wire:click="confirmUpdateDomainDespiteDns"
                                                isError>
                                                Continue
                                            </x-forms.button>
                                        @else
                                            <x-forms.button type="submit" isHighlighted>
                                                Save
                                            </x-forms.button>
                                        @endif
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    @endif

    <x-domain-conflict-modal :conflicts="$domainConflicts" :showModal="$showDomainConflictModal"
        confirmAction="confirmDomainUsage">
        <x-slot:consequences>
            <ul class="mt-2 ml-4 list-disc">
                <li>Only one service will be accessible at this domain</li>
                <li>The routing behavior will be unpredictable</li>
                <li>You may experience service disruptions</li>
                <li>SSL certificates might not work correctly</li>
            </ul>
        </x-slot:consequences>
    </x-domain-conflict-modal>

    @if ($showPortWarningModal)
        <div x-data="{ modalOpen: true }" x-init="$nextTick(() => { modalOpen = true })"
            @keydown.escape.window="modalOpen = false; $wire.call('cancelRemovePort')"
            :class="{ 'z-40': modalOpen }" class="relative w-auto h-auto">
            <template x-teleport="body">
                <div x-show="modalOpen"
                    class="fixed top-0 lg:pt-10 left-0 z-99 flex items-start justify-center w-screen h-screen" x-cloak>
                    <div x-show="modalOpen" class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs"></div>
                    <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen"
                        class="relative w-full py-6 border rounded-sm min-w-full lg:min-w-[36rem] max-w-[48rem] bg-neutral-100 border-neutral-400 dark:bg-base px-7 dark:border-coolgray-300">
                        <h2 class="pr-8 font-bold pb-3">Remove Required Port?</h2>
                        <x-callout type="warning" title="Port Requirement Warning" class="mb-4">
                            This service requires port <strong>{{ $requiredPort }}</strong>. One or more domains are
                            missing a port number.
                        </x-callout>
                        <div class="flex flex-wrap gap-2 justify-between mt-4">
                            <x-forms.button wire:click="cancelRemovePort"
                                class="w-auto dark:bg-coolgray-200 dark:hover:bg-coolgray-300">
                                Cancel - Keep Port
                            </x-forms.button>
                            <x-forms.button wire:click="confirmRemovePort" isError class="w-auto">
                                I understand, remove port anyway
                            </x-forms.button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    @endif
</div>
