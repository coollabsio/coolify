@php
    $configuredCount = collect($domainRows)->where('is_suggested', false)->count();
    $suggestedCount = collect($domainRows)->where('is_suggested', true)->count();
    $hasRows = count($domainRows) > 0;
    $helperText = $isCompose
        ? 'Manage domains for every service in this Docker Compose application.'
        : 'Manage domains for this application.';
@endphp

<div class="flex flex-col gap-4">
    <x-application.settings-section id="domains-section" title="Domains" :helper="$helperText">
        @can('update', $application)
            <x-slot:actions>
                <x-forms.button wire:click="checkAllDns" wire:loading.attr="disabled" wire:target="checkAllDns,checkDomainDns">
                    <x-reicon name="refresh" class="size-3.5" />
                    Recheck DNS
                </x-forms.button>
            </x-slot:actions>
        @endcan

        @if ($labelsAreWritable)
            <x-callout type="warning" title="Domains managed via labels">
                Container label readonly mode is disabled. Domains must be set in the Labels section on the General page.
            </x-callout>
        @endif

        @if ($isCompose && count($composeServices) === 0)
            <x-callout type="info" title="No services">
                No non-database services found in the Docker Compose file. Domains can only be assigned to application
                services.
            </x-callout>
        @endif

        @cannot('update', $application)
            <x-callout type="danger" title="Insufficient permissions">
                You don't have permission to manage domains. Contact your team administrator for access.
            </x-callout>
        @endcannot

        @if (! $isCompose)
            @if ($labelsAreWritable)
                @if ($application->redirect === 'both')
                    <x-forms.input label="Direction" value="Allow www & non-www" readonly
                        helper="Readonly labels are disabled. You can set the direction in the labels section." />
                @elseif ($application->redirect === 'www')
                    <x-forms.input label="Direction" value="Redirect to www" readonly
                        helper="Readonly labels are disabled. You can set the direction in the labels section." />
                @elseif ($application->redirect === 'non-www')
                    <x-forms.input label="Direction" value="Redirect to non-www" readonly
                        helper="Readonly labels are disabled. You can set the direction in the labels section." />
                @endif
            @else
                <div class="flex w-full flex-col gap-3 sm:flex-row sm:items-end">
                    <div class="min-w-0 flex-1">
                        <x-forms.listbox id="redirect" label="Direction" required :options="[
                            ['value' => 'both', 'label' => 'Allow www & non-www'],
                            ['value' => 'www', 'label' => 'Redirect to www'],
                            ['value' => 'non-www', 'label' => 'Redirect to non-www'],
                        ]" helper="Add <strong>both</strong> www and non-www in Coolify. Both hostnames must resolve to this server so the proxy can serve or redirect them. Do not use a DNS-provider URL redirect record for the non-canonical host; Coolify handles the HTTP redirect. Changes apply when you click Set direction."
                            :disabled="! auth()->user()?->can('update', $application)" />
                    </div>
                    @can('update', $application)
                        <div class="w-full shrink-0 sm:w-auto">
                            <x-modal-confirmation title="Confirm redirection setting?" buttonTitle="Set direction"
                                submitAction="setRedirect" :actions="['All traffic will be redirected to the selected direction.']"
                                confirmationText="{{ ($application->fqdn ?: 'domains') . '/' }}"
                                confirmationLabel="Please confirm the execution of the action by entering the Application URL below"
                                shortConfirmationLabel="Application URL" :confirmWithPassword="false"
                                step2ButtonText="Set direction" canGate="update" :canResource="$application" />
                        </div>
                    @endcan
                </div>
            @endif
        @elseif (! $labelsAreWritable && count($composeServices) > 0)
            <p class="text-sm text-neutral-500 dark:text-fg-dim">
                Per-service www/non-www redirects are available next to each service group in the table below.
            </p>
        @endif
    </x-application.settings-section>

    {{-- Toolbar --}}
    <div class="mt-2 flex flex-wrap items-center gap-2">
        <div class="min-w-0 flex-1">
            <p class="text-[13px] text-neutral-500 dark:text-fg-dim">
                {{ $configuredCount }} domain{{ $configuredCount === 1 ? '' : 's' }}
                @if ($suggestedCount > 0)
                    · {{ $suggestedCount }} suggested
                @endif
            </p>
        </div>
        <div class="ml-auto flex flex-wrap items-center gap-2">
            @can('update', $application)
                @unless ($labelsAreWritable)
                    @if (! $isCompose || count($composeServices) > 0)
                        @include('livewire.project.shared.cloudflare-autoconfigure')
                        <x-modal-input title="Add domain" :closeOutside="false" :wireIgnore="false"
                            canGate="update" :canResource="$application">
                            <x-slot:content>
                                <button type="button"
                                    class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
                                    <x-reicon name="plus" class="size-3.5" />
                                    Add
                                </button>
                            </x-slot:content>
                            <form wire:submit="addDomain" class="application-settings-form flex flex-col gap-4">
                                @if ($isCompose && count($composeServices) > 0)
                                    <x-forms.select label="Service" id="newDomainService" required>
                                        @foreach ($composeServices as $serviceName)
                                            <option value="{{ $serviceName }}">{{ $serviceName }}</option>
                                        @endforeach
                                    </x-forms.select>
                                @endif

                                <x-forms.input id="newDomain" label="Domain URL" placeholder="https://app.example.com"
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
                                    <x-forms.button type="button" wire:click="generateDomain">
                                        Generate domain
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
                @endunless
            @endcan
        </div>
    </div>

    {{-- Table / empty --}}
    <div id="domains-table-section"
        class="application-settings-section-body mt-1 scroll-mt-28 {{ $hasRows ? 'is-flush' : '' }} w-full">
        @if ($isCompose && count($composeServices) === 0 && ! $hasRows)
            <x-empty size="sm" title="No services available"
                description="No non-database services found in the Docker Compose file."
                icon-name="globe" />
        @elseif (! $hasRows)
            <x-empty size="sm" title="No domains configured"
                description="Add your first domain with the + Add button above, or generate one with the server wildcard domain."
                icon-name="globe" />
        @elseif ($isCompose)
            @php
                $grouped = collect($domainRows)->groupBy(fn ($row) => $row['service'] ?? '__unknown');
                $serviceOrder = $composeServices;
                foreach ($grouped->keys() as $name) {
                    if ($name !== '__unknown' && ! in_array($name, $serviceOrder, true)) {
                        $serviceOrder[] = $name;
                    }
                }
            @endphp
            <div class="flex flex-col gap-6">
                @foreach ($serviceOrder as $serviceName)
                    @php
                        $rows = $grouped->get($serviceName, collect());
                        $serviceConfigured = $rows->where('is_suggested', false)->count();
                        $serviceSuggested = $rows->where('is_suggested', true)->count();
                        $redirectWireKey = $this->serviceRedirectWireKey($serviceName);
                    @endphp
                    <div class="flex flex-col gap-3">
                        <div class="flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <h3 class="text-[14px] font-semibold text-black dark:text-fg">{{ $serviceName }}</h3>
                                <p class="mt-0.5 text-[12px] text-neutral-500 dark:text-fg-dim">
                                    {{ $serviceConfigured }} domain{{ $serviceConfigured === 1 ? '' : 's' }}
                                    @if ($serviceSuggested > 0)
                                        · {{ $serviceSuggested }} suggested
                                    @endif
                                </p>
                            </div>
                            @unless ($labelsAreWritable)
                                <div class="flex w-full max-w-xl flex-col gap-2 sm:flex-row sm:items-end">
                                    <div class="min-w-0 flex-1">
                                        <x-forms.select label="Direction" id="serviceRedirects.{{ $redirectWireKey }}"
                                            wire:model="serviceRedirects.{{ $redirectWireKey }}" required
                                            helper="Per-service www/non-www redirect. Add both hosts when using a redirect."
                                            canGate="update" :canResource="$application">
                                            <option value="both">Allow www & non-www</option>
                                            <option value="www">Redirect to www</option>
                                            <option value="non-www">Redirect to non-www</option>
                                        </x-forms.select>
                                    </div>
                                    @can('update', $application)
                                        <x-modal-confirmation title="Confirm redirection setting?" buttonTitle="Set direction"
                                            submitAction='setServiceRedirect({{ \Illuminate\Support\Js::from($serviceName) }})'
                                            :actions="['Traffic for this service will be redirected to the selected direction. Missing www/non-www counterparts will be added automatically when possible.']"
                                            confirmationText="{{ $serviceName }}/"
                                            confirmationLabel="Please confirm by entering the service name below"
                                            shortConfirmationLabel="Service name" :confirmWithPassword="false"
                                            step2ButtonText="Set direction" canGate="update" :canResource="$application" />
                                    @endcan
                                </div>
                            @endunless
                        </div>

                        @if ($rows->isEmpty())
                            <x-empty size="sm" title="No domains for this service"
                                description="Use + Add and select {{ $serviceName }}."
                                icon-name="globe" />
                        @else
                            <div class="data-table w-full">
                                <div class="data-table-header domains-table-grid-compose">
                                    <span>Domain</span>
                                    <span>Service</span>
                                    <span>DNS</span>
                                    <span>Last checked</span>
                                    <span></span>
                                </div>
                                @foreach ($rows as $row)
                                    @php
                                        $index = collect($domainRows)->search(
                                            fn ($item) => $item['url'] === $row['url']
                                                && ($item['service'] ?? null) === ($row['service'] ?? null)
                                                && (bool) ($item['is_suggested'] ?? false) === (bool) ($row['is_suggested'] ?? false),
                                        );
                                    @endphp
                                    @include('livewire.project.application.partials.domain-row', [
                                        'index' => $index,
                                        'row' => $row,
                                        'application' => $application,
                                        'labelsAreWritable' => $labelsAreWritable,
                                        'isCompose' => true,
                                    ])
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <div class="data-table w-full">
                <div class="data-table-header domains-table-grid">
                    <span>Domain</span>
                    <span>DNS</span>
                    <span>Last checked</span>
                    <span></span>
                </div>
                @foreach ($domainRows as $index => $row)
                    @include('livewire.project.application.partials.domain-row', [
                        'index' => $index,
                        'row' => $row,
                        'application' => $application,
                        'labelsAreWritable' => $labelsAreWritable,
                        'isCompose' => false,
                    ])
                @endforeach
            </div>
        @endif
    </div>

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
                                <button type="button" wire:click="cancelEdit"
                                    class="icon-button shrink-0" aria-label="Close">
                                    <x-reicon name="x" class="size-4" />
                                </button>
                            </header>
                            <div class="application-settings-section-body relative min-h-0 flex-1 overflow-y-auto"
                                style="-webkit-overflow-scrolling: touch;">
                                <form wire:submit="updateDomain" class="flex flex-col gap-4">
                                    @if ($editingService)
                                        <x-forms.input label="Service" value="{{ $editingService }}" readonly />
                                    @endif

                                    <x-forms.input id="editingDomain" label="Domain URL"
                                        placeholder="https://app.example.com"
                                        helper="Full URL including scheme. Optional path and container port are supported.<br><br><span class='text-helper'>Examples</span><br>- https://app.coolify.io<br>- https://app.coolify.io/api/v3<br>- https://app.coolify.io:3000<br>- https://app.coolify.io:8080/api"
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
