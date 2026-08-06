@php
    $configuredCount = collect($domainRows)->where('is_suggested', false)->count();
    $suggestedCount = collect($domainRows)->where('is_suggested', true)->count();
    $hasRows = count($domainRows) > 0;
    $serviceAppCount = count($serviceApps);
    $domainGroups = collect($domainRows)
        ->groupBy('service_application_id')
        ->filter(fn ($rows) => $rows->contains(fn ($row) => ! ($row['is_suggested'] ?? false)));
@endphp

<div class="flex flex-col gap-4"
    x-data="{
        modalOpen: @js($showEditDomainModal || $editDomainDnsFailed),
        editingServiceLabel: '',
        localEditingIndex: @js($editingIndex),
        localEditingDomain: @js($editingDomain),
        localEditingServiceApplicationId: @js($editingServiceApplicationId),
        openEditDomain(index, url, serviceApplicationId, serviceLabel) {
            this.localEditingIndex = index;
            this.localEditingDomain = url;
            this.localEditingServiceApplicationId = serviceApplicationId;
            this.editingServiceLabel = serviceLabel || '';
            this.modalOpen = true;
            this.$nextTick(() => document.getElementById('editingDomainLocal')?.focus?.());
        },
        closeEditDomain() {
            this.modalOpen = false;
            this.editingServiceLabel = '';
            this.localEditingIndex = null;
            this.localEditingDomain = '';
            this.localEditingServiceApplicationId = null;
        },
        prepareEditSubmit() {
            $wire.editingIndex = this.localEditingIndex;
            $wire.editingDomain = this.localEditingDomain;
            $wire.editingServiceApplicationId = this.localEditingServiceApplicationId;
            $wire.showEditDomainModal = true;
        },
    }"
    @open-edit-domain.window="openEditDomain($event.detail.index, $event.detail.url, $event.detail.serviceApplicationId, $event.detail.serviceLabel)">
    <x-application.settings-section id="service-domains-section" title="Domains">
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

        <p class="text-sm text-neutral-500 dark:text-fg-dim">
            Manage domains and www/non-www redirects for applications in this stack.
        </p>

    </x-application.settings-section>

    {{-- Toolbar --}}
    <div class="mt-2 flex flex-wrap items-center gap-2">
        <p class="min-w-0 flex-1 text-[13px] text-neutral-500 dark:text-fg-dim">
            {{ $configuredCount }} domain{{ $configuredCount === 1 ? '' : 's' }}
            @if ($suggestedCount > 0)
                · {{ $suggestedCount }} not added
            @endif
        </p>
        <div class="ml-auto flex flex-wrap items-center gap-2">
            @can('update', $service)
                @if ($serviceAppCount > 0)
                    @include('livewire.project.shared.cloudflare-autoconfigure')
                    <x-modal-input title="Add domain" :closeOutside="false" :wireIgnore="false"
                        canGate="update" :canResource="$service">
                        <x-slot:content>
                            <button type="button"
                                class="button button-highlighted">
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
                                <x-callout type="danger" title="DNS is not pointing to the right IP">
                                    This domain does not currently resolve to this server.
                                    Traffic may not reach Coolify until you update DNS.
                                    Are you sure you want to add it anyway?
                                    @if (filled($addDomainDnsMessage))
                                        <div class="pt-2">{{ $addDomainDnsMessage }}</div>
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
        <div wire:key="service-domains-list"
            class="application-settings-section-body is-flush mt-1 w-full scroll-mt-28 overflow-hidden">
            @foreach ($domainGroups as $appId => $rows)
                @php
                    $app = collect($serviceApps)->firstWhere('id', (int) $appId);
                    $heading = \Illuminate\Support\Str::headline($app['name'] ?? $rows->first()['service_name'] ?? 'Service');
                    $appDomainCount = $rows->where('is_suggested', false)->count();
                    $redirect = $serviceRedirects[$appId] ?? 'both';
                    $redirectLabel = match ($redirect) {
                        'www' => 'Redirect to www',
                        'non-www' => 'Redirect to non-www',
                        default => 'Allow both',
                    };
                @endphp
                <section id="service-domain-group-{{ $appId }}" wire:key="service-domain-group-{{ $appId }}"
                    class="border-b border-neutral-200 last:border-b-0 dark:border-white/10">
                    <div class="flex w-full items-center gap-3 px-4 py-3">
                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-black dark:text-white">{{ $heading }}</span>
                        <span class="hidden shrink-0 text-xs text-neutral-500 sm:inline dark:text-fg-dim">
                            {{ $appDomainCount }} domain{{ $appDomainCount === 1 ? '' : 's' }}
                        </span>
                        @can('update', $service)
                            <div class="relative flex shrink-0 items-center gap-2 px-1 py-1 text-sm text-neutral-600 dark:text-fg-dim"
                                wire:loading.class="opacity-50" wire:target="serviceRedirects.{{ $appId }}">
                                <span>{{ $redirectLabel }}</span>
                                <x-reicon name="chevron-down" class="size-4 shrink-0"
                                    wire:loading.remove wire:target="serviceRedirects.{{ $appId }}" />
                                <x-loading-on-button wire:loading.delay wire:target="serviceRedirects.{{ $appId }}" />
                                <select id="service-domain-redirect-{{ $appId }}"
                                    wire:model.change="serviceRedirects.{{ $appId }}"
                                    wire:loading.attr="disabled" wire:target="serviceRedirects.{{ $appId }}"
                                    class="absolute inset-0 size-full cursor-pointer opacity-0 disabled:cursor-wait"
                                    aria-label="Redirect direction for {{ $heading }}">
                                    <option value="both">Allow www & non-www</option>
                                    <option value="www">Redirect to www</option>
                                    <option value="non-www">Redirect to non-www</option>
                                </select>
                            </div>
                        @else
                            <span class="shrink-0 text-sm text-neutral-600 dark:text-fg-dim">{{ $redirectLabel }}</span>
                        @endcan
                    </div>

                    <div wire:key="service-domain-rows-{{ $appId }}-{{ md5(serialize($rows->all())) }}">
                        @include('livewire.project.service.partials.domain-table', [
                            'rows' => $rows,
                            'domainRows' => $domainRows,
                            'service' => $service,
                            'showServiceColumn' => false,
                            'showHeader' => false,
                        ])
                    </div>
                </section>
            @endforeach
        </div>
    @endif

    {{-- Edit domain modal: open/close is Alpine-only; server runs only on Save / Continue. --}}
    <div class="relative h-auto w-auto" :class="{ 'z-40': modalOpen }"
        @keydown.window.escape="if (modalOpen) { closeEditDomain() }">
        <template x-teleport="body">
            <div x-show="modalOpen" class="fixed inset-0 z-99 overflow-y-auto" x-cloak>
                <div x-show="modalOpen" x-transition:enter="ease-out duration-100"
                    x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                    x-transition:leave="ease-in duration-100" x-transition:leave-start="opacity-100"
                    x-transition:leave-end="opacity-0"
                    class="absolute inset-0 h-full w-full bg-black/50 backdrop-blur-[2px]"
                    @click="closeEditDomain()"></div>
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
                            <button type="button" @click="closeEditDomain()" class="icon-button shrink-0"
                                aria-label="Close">
                                <x-reicon name="x" class="size-4" />
                            </button>
                        </header>
                        <div class="application-settings-section-body relative min-h-0 flex-1 overflow-y-auto"
                            style="-webkit-overflow-scrolling: touch;">
                            <form @submit.prevent="prepareEditSubmit(); $wire.updateDomain()" class="flex flex-col gap-4">
                                <div x-show="editingServiceLabel" x-cloak class="w-full">
                                    <div class="mb-1.5 flex h-4 w-full items-center gap-1.5">
                                        <label class="mb-0! flex items-center gap-1 text-sm font-medium leading-4">Service application</label>
                                    </div>
                                    <input type="text" class="input" readonly x-bind:value="editingServiceLabel" />
                                    <p class="mt-1 text-[12px] text-neutral-500 dark:text-fg-dim">
                                        Domains stay on the service they were added to. Remove and re-add to move.
                                    </p>
                                </div>

                                <div class="w-full">
                                    <div class="mb-1.5 flex h-4 w-full items-center gap-1.5">
                                        <label class="mb-0! flex items-center gap-1 text-sm font-medium leading-4" for="editingDomainLocal">
                                            Domain URL <x-highlighted text="*" />
                                        </label>
                                    </div>
                                    <input id="editingDomainLocal" type="url" class="input" required
                                        placeholder="https://app.example.com"
                                        x-model="localEditingDomain" />
                                    @error('editingDomain')
                                        <p class="mt-1 text-[12px] text-red-500">{{ $message }}</p>
                                    @enderror
                                </div>

                                @if ($editDomainDnsFailed)
                                    <x-callout type="danger" title="DNS is not pointing to the right IP">
                                        This domain does not currently resolve to this server.
                                        Traffic may not reach Coolify until you update DNS.
                                        Are you sure you want to save it anyway?
                                        @if (filled($editDomainDnsMessage))
                                            <div class="pt-2">{{ $editDomainDnsMessage }}</div>
                                        @endif
                                    </x-callout>
                                @endif

                                <div class="flex flex-wrap items-center justify-end gap-2 pt-2">
                                    <x-forms.button type="button" @click="closeEditDomain()">
                                        Cancel
                                    </x-forms.button>
                                    @if ($editDomainDnsFailed)
                                        <x-forms.button type="button" isError
                                            @click="prepareEditSubmit(); $wire.forceSaveEditDns = true; $wire.confirmUpdateDomainDespiteDns()">
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

    <x-domain-conflict-modal :conflicts="$domainConflicts" :showModal="$showDomainConflictModal"
        confirmAction="confirmDomainUsage" />
</div>
