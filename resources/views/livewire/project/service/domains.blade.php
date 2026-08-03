@php
    $configuredCount = collect($domainRows)->where('is_suggested', false)->count();
    $suggestedCount = collect($domainRows)->where('is_suggested', true)->count();
    $hasRows = count($domainRows) > 0;
    $serviceAppCount = count($serviceApps);
    $isSingleService = $serviceAppCount === 1;
    $singleApp = $isSingleService ? collect($serviceApps)->first() : null;
    $singleAppId = $singleApp['id'] ?? null;
@endphp

<div class="flex flex-col gap-4">
    <x-application.settings-section id="service-domains-section" title="Domains"
        helper="Manage domains and www/non-www redirects for applications in this stack.">
        @can('update', $service)
            <x-slot:actions>
                <x-forms.button wire:click="checkAllDns" wire:loading.attr="disabled"
                    wire:target="checkAllDns,checkDomainDns">
                    <x-reicon name="refresh" class="size-3.5" />
                    Recheck DNS
                </x-forms.button>
            </x-slot:actions>
        @endcan

        @cannot('update', $service)
            <x-callout type="danger" title="Insufficient permissions">
                You don't have permission to manage domains. Contact your team administrator for access.
            </x-callout>
        @endcannot

        @if ($isSingleService && $singleAppId)
            <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-end">
                <div class="min-w-0 flex-1">
                    <x-forms.select canGate="update" :canResource="$service" label="Direction"
                        id="serviceRedirects.{{ $singleAppId }}" wire:model="serviceRedirects.{{ $singleAppId }}"
                        required
                        helper="Add both www and non-www hosts when using a redirect. Both hostnames must resolve to this server.">
                        <option value="both">Allow www & non-www</option>
                        <option value="www">Redirect to www</option>
                        <option value="non-www">Redirect to non-www</option>
                    </x-forms.select>
                </div>
                @can('update', $service)
                    <div class="w-full shrink-0 sm:w-auto">
                        <x-modal-confirmation title="Confirm redirection setting?" buttonTitle="Set direction"
                            submitAction="setServiceRedirect({{ (int) $singleAppId }})"
                            :actions="['Traffic for this service will be redirected to the selected direction.']"
                            confirmationText="{{ ($singleApp['name'] ?? 'service') . '/' }}"
                            confirmationLabel="Please confirm by entering the service name below"
                            shortConfirmationLabel="Service name" :confirmWithPassword="false"
                            step2ButtonText="Set direction" canGate="update" :canResource="$service" />
                    </div>
                @endcan
            </div>
        @elseif ($serviceAppCount > 1)
            <p class="text-sm text-neutral-500 dark:text-fg-dim">
                Domains are listed with their service name. Set each service redirect from the group controls under the table when needed.
            </p>
        @endif
    </x-application.settings-section>

    {{-- Toolbar --}}
    <div class="mt-2 flex flex-wrap items-center gap-2">
        <p class="min-w-0 flex-1 text-[13px] text-neutral-500 dark:text-fg-dim">
            {{ $configuredCount }} domain{{ $configuredCount === 1 ? '' : 's' }}
            @if ($suggestedCount > 0)
                · {{ $suggestedCount }} suggested
            @endif
        </p>
        <div class="ml-auto flex flex-wrap items-center gap-2">
            @can('update', $service)
                @if ($serviceAppCount > 0)
                    <x-modal-input title="Add domain" :closeOutside="false" :wireIgnore="false"
                        canGate="update" :canResource="$service">
                        <x-slot:content>
                            <button type="button"
                                class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
                                <x-reicon name="plus" class="size-3.5" />
                                Add
                            </button>
                        </x-slot:content>
                        <form wire:submit="addDomain" class="application-settings-form flex flex-col gap-4">
                            {{-- Always show which service receives the domain --}}
                            <x-forms.select canGate="update" :canResource="$service" label="Service application"
                                id="newServiceApplicationId" required
                                helper="Domain will be assigned to this compose service application.">
                                @foreach ($serviceApps as $app)
                                    <option value="{{ $app['id'] }}">
                                        {{ $app['name'] }}{{ filled($app['image'] ?? null) ? ' ('.$app['image'].')' : '' }}
                                    </option>
                                @endforeach
                            </x-forms.select>

                            <x-forms.input canGate="update" :canResource="$service" id="newDomain" label="Domain URL"
                                placeholder="https://app.example.com"
                                helper="Full URL including scheme. Optional path and container port are supported.<br><br><span class='text-helper'>Examples</span><br>- https://app.coolify.io<br>- https://app.coolify.io/api/v3<br>- https://app.coolify.io:3000<br>- https://app.coolify.io:8080/api"
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
                                <x-forms.button canGate="update" :canResource="$service" type="button"
                                    wire:click="generateDomain">
                                    Generate domain
                                </x-forms.button>
                                <div class="flex flex-wrap gap-2">
                                    @if ($addDomainDnsFailed)
                                        <x-forms.button canGate="update" :canResource="$service" type="button"
                                            wire:click="confirmAddDomainDespiteDns" isError>
                                            Continue
                                        </x-forms.button>
                                    @else
                                        <x-forms.button canGate="update" :canResource="$service" type="submit"
                                            isHighlighted>
                                            Save
                                        </x-forms.button>
                                    @endif
                                </div>
                            </div>
                        </form>
                    </x-modal-input>
                @endif
            @endcan
        </div>
    </div>

    @if ($serviceAppCount === 0)
        <div class="application-settings-section-body mt-1 w-full scroll-mt-28">
            <x-empty size="sm" title="No application services"
                description="Only database services are available. Domains can only be assigned to application services."
                icon-name="globe" />
        </div>
    @elseif (! $hasRows)
        <div class="application-settings-section-body mt-1 w-full scroll-mt-28">
            <x-empty size="sm" title="No domains configured"
                description="Add your first domain with the + Add button above. Choose which service application receives it."
                icon-name="globe" />
        </div>
    @else
        {{-- Flat table with Service column so assignment is always visible --}}
        <div class="application-settings-section-body is-flush mt-1 w-full scroll-mt-28">
            @include('livewire.project.service.partials.domain-table', [
                'rows' => collect($domainRows),
                'domainRows' => $domainRows,
                'service' => $service,
                'showServiceColumn' => true,
            ])
        </div>

        {{-- Multi-service redirect controls under the table --}}
        @if ($serviceAppCount > 1)
            <div class="mt-2 flex flex-col gap-4">
                @foreach ($serviceApps as $app)
                    @php
                        $appId = $app['id'];
                        $heading = $app['name'] ?? 'Service';
                        $appDomainCount = collect($domainRows)
                            ->where('service_application_id', $appId)
                            ->where('is_suggested', false)
                            ->count();
                    @endphp
                    <x-application.settings-section
                        :id="'service-domain-redirect-'.$appId"
                        :title="\Illuminate\Support\Str::headline($heading)"
                        :helper="$appDomainCount.' domain'.($appDomainCount === 1 ? '' : 's')">
                        <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-end">
                            <div class="min-w-0 flex-1">
                                <x-forms.select canGate="update" :canResource="$service" label="Direction"
                                    id="serviceRedirects.{{ $appId }}" wire:model="serviceRedirects.{{ $appId }}"
                                    required
                                    helper="Per-service www/non-www redirect. Add both hosts when using a redirect.">
                                    <option value="both">Allow www & non-www</option>
                                    <option value="www">Redirect to www</option>
                                    <option value="non-www">Redirect to non-www</option>
                                </x-forms.select>
                            </div>
                            @can('update', $service)
                                <div class="w-full shrink-0 sm:w-auto">
                                    <x-modal-confirmation title="Confirm redirection setting?" buttonTitle="Set direction"
                                        submitAction="setServiceRedirect({{ (int) $appId }})"
                                        :actions="['Traffic for this service will be redirected to the selected direction.']"
                                        confirmationText="{{ ($heading ?: 'service') . '/' }}"
                                        confirmationLabel="Please confirm by entering the service name below"
                                        shortConfirmationLabel="Service name" :confirmWithPassword="false"
                                        step2ButtonText="Set direction" canGate="update" :canResource="$service" />
                                </div>
                            @endcan
                        </div>
                    </x-application.settings-section>
                @endforeach
            </div>
        @endif
    @endif

    {{-- Edit domain modal --}}
    @if ($showEditDomainModal)
        <div x-data="{ modalOpen: @entangle('showEditDomainModal') }"
            @keydown.escape.window="modalOpen = false; $wire.cancelEdit()"
            class="relative h-auto w-auto" :class="{ 'z-40': modalOpen }">
            <template x-teleport="body">
                <div x-show="modalOpen" class="fixed inset-0 z-99 overflow-y-auto" x-cloak>
                    <div x-show="modalOpen" x-transition:enter="ease-out duration-100"
                        x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                        x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
                        x-transition:leave-end="opacity-0"
                        class="absolute inset-0 h-full w-full bg-black/50 backdrop-blur-[2px]"
                        @click="modalOpen = false; $wire.cancelEdit()"></div>
                    <div class="relative flex min-h-full items-start justify-center p-4 sm:items-center">
                        <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen"
                            x-transition:enter="ease-out duration-100"
                            x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                            x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                            x-transition:leave="ease-in duration-100"
                            x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                            x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                            class="application-settings-form application-settings-section relative flex max-h-[calc(100dvh-2rem)] w-full flex-col overflow-hidden lg:w-auto lg:min-w-2xl lg:max-w-4xl"
                            style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                            <header class="flex-nowrap!">
                                <h3 class="min-w-0 flex-1 truncate">Edit domain</h3>
                                <button type="button" wire:click="cancelEdit" class="icon-button shrink-0"
                                    aria-label="Close">
                                    <x-reicon name="x" class="size-4" />
                                </button>
                            </header>
                            <div class="application-settings-section-body relative min-h-0 flex-1 overflow-y-auto"
                                style="-webkit-overflow-scrolling: touch;">
                                <form wire:submit="updateDomain" class="flex flex-col gap-4">
                                    @php
                                        $editingServiceLabel = collect($serviceApps)
                                            ->firstWhere('id', (int) $editingServiceApplicationId)['name']
                                            ?? data_get($domainRows, ($editingIndex ?? -1).'.service_name');
                                    @endphp
                                    @if (filled($editingServiceLabel))
                                        <x-forms.input label="Service application" value="{{ $editingServiceLabel }}"
                                            readonly
                                            helper="Domains stay on the service they were added to. Remove and re-add to move." />
                                    @endif

                                    <x-forms.input id="editingDomain" label="Domain URL"
                                        placeholder="https://app.example.com"
                                        helper="Full URL including scheme. Optional path and container port are supported."
                                        required />

                                    @if ($editDomainDnsFailed)
                                        <x-callout type="danger" title="DNS validation failed">
                                            {{ $editDomainDnsMessage }}
                                            @if ($serverIp)
                                                <div class="pt-2 text-sm">
                                                    Expected target:
                                                    <span class="font-mono">{{ $this->dnsTargetLabel() ?? $serverIp }}</span>
                                                </div>
                                            @endif
                                        </x-callout>
                                    @endif

                                    <div class="flex flex-wrap items-center justify-end gap-2 pt-2">
                                        <x-forms.button type="button" wire:click="cancelEdit">
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
        confirmAction="confirmDomainUsage" />
</div>
