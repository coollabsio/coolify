<div class="flex flex-col gap-4">
    <div>
        <div class="flex flex-wrap items-center gap-2">
            <h2>Domains</h2>
            @can('update', $application)
                @unless ($labelsAreWritable)
                    <x-modal-input buttonTitle="+ Add" title="Add Domain" isHighlightedButton :wireIgnore="false"
                        :closeOutside="false" canGate="update" :canResource="$application">
                        <form wire:submit="addDomain" class="flex flex-col gap-4">
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
                @endunless
                <x-forms.button wire:click="checkAllDns">
                    Recheck DNS
                </x-forms.button>
            @endcan
        </div>
        <div class="text-sm dark:text-neutral-400">
            @if ($isCompose)
                Manage domains for every service in this Docker Compose application.
            @else
                Manage domains for this application.
            @endif
        </div>
    </div>

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
        <x-callout type="danger" title="Insufficient Permissions">
            You don't have permission to manage domains. Contact your team administrator for access.
        </x-callout>
    @endcannot

    @if (! $isCompose)
        <div class="flex flex-col gap-2 md:flex-row md:items-end md:max-w-xl">
            @if ($labelsAreWritable)
                @if ($application->redirect === 'both')
                    <x-forms.input label="Direction" value="Allow www & non-www." readonly
                        helper="Readonly labels are disabled. You can set the direction in the labels section." />
                @elseif ($application->redirect === 'www')
                    <x-forms.input label="Direction" value="Redirect to www." readonly
                        helper="Readonly labels are disabled. You can set the direction in the labels section." />
                @elseif ($application->redirect === 'non-www')
                    <x-forms.input label="Direction" value="Redirect to non-www." readonly
                        helper="Readonly labels are disabled. You can set the direction in the labels section." />
                @endif
            @else
                <div class="flex-1 min-w-0">
                    <x-forms.select label="Direction" id="redirect" wire:model="redirect" required
                        helper="Add <strong>both</strong> www and non-www in Coolify. Both hostnames must resolve to this server so the proxy can serve or redirect them. Do not use a DNS-provider URL redirect record for the non-canonical host; Coolify handles the HTTP redirect. Changes apply when you click Set Direction.">
                        <option value="both">Allow www & non-www.</option>
                        <option value="www">Redirect to www.</option>
                        <option value="non-www">Redirect to non-www.</option>
                    </x-forms.select>
                </div>
                @can('update', $application)
                    <x-modal-confirmation title="Confirm Redirection Setting?" buttonTitle="Set Direction"
                        submitAction="setRedirect" :actions="['All traffic will be redirected to the selected direction.']"
                        confirmationText="{{ ($application->fqdn ?: 'domains') . '/' }}"
                        confirmationLabel="Please confirm the execution of the action by entering the Application URL below"
                        shortConfirmationLabel="Application URL" :confirmWithPassword="false"
                        step2ButtonText="Set Direction" canGate="update" :canResource="$application">
                        <x-slot:customButton>
                            <div class="w-[7.2rem]">Set Direction</div>
                        </x-slot:customButton>
                    </x-modal-confirmation>
                @endcan
            @endif
        </div>
    @endif

    <div class="flex flex-col gap-3">
        @if (! $isCompose)
            @php
                $configuredCount = collect($domainRows)->where('is_suggested', false)->count();
                $suggestedCount = collect($domainRows)->where('is_suggested', true)->count();
            @endphp
            <div class="flex items-center justify-between gap-2">
                <h3>Configured domains</h3>
                <span class="text-xs dark:text-neutral-400">
                    {{ $configuredCount }} domain(s)
                    @if ($suggestedCount > 0)
                        · {{ $suggestedCount }} suggested
                    @endif
                </span>
            </div>
        @endif

        @if ($isCompose && count($composeServices) === 0 && count($domainRows) === 0)
            {{-- empty handled by callout above --}}
        @elseif (count($domainRows) === 0 && ! $isCompose)
            <div class="p-6 text-sm border border-dashed rounded-sm dark:border-coolgray-300 dark:text-neutral-400">
                No domains configured yet.
                @can('update', $application)
                    @unless ($labelsAreWritable)
                        Use <strong>+ Add</strong> or generate one with the server wildcard domain.
                    @endunless
                @endcan
            </div>
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
                        $configuredCount = $rows->where('is_suggested', false)->count();
                        $suggestedCount = $rows->where('is_suggested', true)->count();
                    @endphp
                    <div class="flex flex-col gap-2">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <h3 class="text-base font-semibold">{{ $serviceName }}</h3>
                            <span class="text-xs dark:text-neutral-400">
                                {{ $configuredCount }} domain(s)
                                @if ($suggestedCount > 0)
                                    · {{ $suggestedCount }} suggested
                                @endif
                            </span>
                        </div>

                        @unless ($labelsAreWritable)
                            @php
                                $redirectWireKey = $this->serviceRedirectWireKey($serviceName);
                            @endphp
                            <div class="flex flex-col gap-2 md:flex-row md:items-end md:max-w-xl">
                                <div class="flex-1 min-w-0">
                                    <x-forms.select label="Direction" id="serviceRedirects.{{ $redirectWireKey }}"
                                        wire:model="serviceRedirects.{{ $redirectWireKey }}" required
                                        helper="Per-service www/non-www redirect. Add <strong>both</strong> hosts for this service when using a redirect. Both hostnames must resolve to this server so the proxy can serve or redirect them. Changes apply when you click Set Direction.">
                                        <option value="both">Allow www & non-www.</option>
                                        <option value="www">Redirect to www.</option>
                                        <option value="non-www">Redirect to non-www.</option>
                                    </x-forms.select>
                                </div>
                                @can('update', $application)
                                    {{-- Single-quoted attr so Js::from double-quotes don't break HTML. --}}
                                    <x-modal-confirmation title="Confirm Redirection Setting?" buttonTitle="Set Direction"
                                        submitAction='setServiceRedirect({{ \Illuminate\Support\Js::from($serviceName) }})'
                                        :actions="['Traffic for this service will be redirected to the selected direction. Missing www/non-www counterparts will be added automatically when possible.']"
                                        confirmationText="{{ $serviceName }}/"
                                        confirmationLabel="Please confirm by entering the service name below"
                                        shortConfirmationLabel="Service name" :confirmWithPassword="false"
                                        step2ButtonText="Set Direction" canGate="update" :canResource="$application">
                                        <x-slot:customButton>
                                            <div class="w-[7.2rem]">Set Direction</div>
                                        </x-slot:customButton>
                                    </x-modal-confirmation>
                                @endcan
                            </div>
                        @endunless

                        @if ($rows->isEmpty())
                            <div
                                class="p-4 text-sm border border-dashed rounded-sm dark:border-coolgray-300 dark:text-neutral-400">
                                No domains for this service yet.
                                @can('update', $application)
                                    @unless ($labelsAreWritable)
                                        Use <strong>+ Add</strong> and select <span class="font-mono">{{ $serviceName }}</span>.
                                    @endunless
                                @endcan
                            </div>
                        @else
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
                                ])
                            @endforeach
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <div class="flex flex-col gap-2">
                @foreach ($domainRows as $index => $row)
                    @include('livewire.project.application.partials.domain-row', [
                        'index' => $index,
                        'row' => $row,
                        'application' => $application,
                        'labelsAreWritable' => $labelsAreWritable,
                    ])
                @endforeach
            </div>
        @endif
    </div>

    {{-- Edit domain modal (same flow as Add Domain) --}}
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
                                    class="absolute cursor-pointer top-0 right-0 flex items-center justify-center w-8 h-8 mt-5 mr-5 rounded-full dark:text-white hover:bg-neutral-100 dark:hover:bg-coolgray-300 outline-0 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-coollabs dark:focus-visible:ring-warning focus-visible:ring-offset-2 dark:focus-visible:ring-offset-base">
                                    <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none"
                                        viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>
                            <div class="relative min-h-0 flex-1 overflow-y-auto px-6 pb-6 pt-1"
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
                                                    <span
                                                        class="font-mono">{{ $this->dnsTargetLabel() ?? $serverIp }}</span>
                                                </div>
                                            @endif
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
        confirmAction="confirmDomainUsage" />
</div>
