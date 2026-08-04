<div class="w-full">
    <x-slot:title>
        New Server | Coolify
    </x-slot>

    <div class="mb-4 flex flex-wrap items-center justify-end gap-2">
        <a href="{{ $selectedType ? route('server.create') : route('server.index') }}" class="button"
            {{ wireNavigate() }}>
            {{ $selectedType ? 'Change method' : 'Back to servers' }}
        </a>
        @if ($selectedType && $selectedType !== 'manual' && ! $selectedTokenUuid)
            @php
                $tokenProvider = $selectedType === 'digital-ocean' ? 'digitalocean' : $selectedType;
                $tokenProviderName = $selectedType === 'digital-ocean'
                    ? 'DigitalOcean'
                    : str($selectedType)->headline();
            @endphp
            @can('create', App\Models\CloudProviderToken::class)
                <x-modal-input title="New {{ $tokenProviderName }} token">
                    <x-slot:content>
                        <button type="button"
                            class="button bg-coollabs/10! text-coollabs! ring-1 ring-coollabs/25 hover:bg-coollabs/15! dark:bg-warning/15! dark:text-warning! dark:ring-warning/25 dark:hover:bg-warning/20!">
                            <x-reicon name="plus" class="size-3.5" />
                            New token
                        </button>
                    </x-slot:content>
                    <livewire:security.cloud-provider-token-form :modal_mode="true" :provider="$tokenProvider"
                        wire:key="new-server-token-{{ $tokenProvider }}" />
                </x-modal-input>
            @endcan
        @endif
    </div>

    @if (!$selectedType)
        <div class="application-settings-form">
            <x-application.settings-section title="Add a server"
                description="Provision with a cloud provider or connect any reachable Linux server." flush>
                <div class="grid grid-cols-1 gap-3 p-3 sm:grid-cols-2 lg:grid-cols-4">
                    @can('viewAny', App\Models\CloudProviderToken::class)
                        <a href="{{ route('server.create.type', ['type' => 'hetzner']) }}"
                            class="group flex min-h-32 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]"
                            {{ wireNavigate() }}>
                            <div class="flex items-start justify-between gap-3">
                                <span
                                    class="flex size-8 items-center justify-center rounded-lg bg-[#D50C2D] text-[12px] font-bold text-white">H</span>
                                <span
                                    class="rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-medium text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                                    Provider
                                </span>
                            </div>
                            <div class="mt-auto pt-5">
                                <h3 class="text-[13px]! font-semibold! text-black dark:text-fg">Hetzner</h3>
                                <p class="mt-1 text-[11px] leading-4 text-neutral-500 dark:text-fg-faint">
                                    Provision from Hetzner Cloud.
                                </p>
                            </div>
                        </a>

                        <a href="{{ route('server.create.type', ['type' => 'vultr']) }}"
                            class="group flex min-h-32 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]"
                            {{ wireNavigate() }}>
                            <div class="flex items-start justify-between gap-3">
                                <span
                                    class="flex size-8 items-center justify-center rounded-lg bg-[#007BFC] text-[12px] font-bold text-white">V</span>
                                <span
                                    class="rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-medium text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                                    Provider
                                </span>
                            </div>
                            <div class="mt-auto pt-5">
                                <h3 class="text-[13px]! font-semibold! text-black dark:text-fg">Vultr</h3>
                                <p class="mt-1 text-[11px] leading-4 text-neutral-500 dark:text-fg-faint">
                                    Provision from Vultr Cloud.
                                </p>
                            </div>
                        </a>

                        <a href="{{ route('server.create.type', ['type' => 'digital-ocean']) }}"
                            class="group flex min-h-32 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]"
                            {{ wireNavigate() }}>
                            <div class="flex items-start justify-between gap-3">
                                <x-digital-ocean-icon class="size-8" />
                                <span
                                    class="rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-medium text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                                    Provider
                                </span>
                            </div>
                            <div class="mt-auto pt-5">
                                <h3 class="text-[13px]! font-semibold! text-black dark:text-fg">DigitalOcean</h3>
                                <p class="mt-1 text-[11px] leading-4 text-neutral-500 dark:text-fg-faint">
                                    Provision a new Droplet.
                                </p>
                            </div>
                        </a>
                    @endcan

                    <a href="{{ route('server.create.type', ['type' => 'manual']) }}"
                        class="group flex min-h-32 flex-col rounded-xl border border-neutral-200 bg-white p-3 shadow-sm transition-all hover:-translate-y-px hover:border-neutral-300 hover:no-underline hover:shadow-md dark:border-white/[0.08] dark:bg-white/[0.025] dark:hover:border-white/[0.14]"
                        {{ wireNavigate() }}>
                        <div class="flex items-start justify-between gap-3">
                            <span
                                class="flex size-8 items-center justify-center rounded-lg border border-neutral-200 bg-neutral-50 text-neutral-500 dark:border-white/[0.08] dark:bg-white/[0.04] dark:text-fg-dim">
                                <x-reicon name="servers" class="size-4" />
                            </span>
                            <span
                                class="rounded-full bg-neutral-100 px-2 py-0.5 text-[10px] font-medium text-neutral-600 dark:bg-white/[0.06] dark:text-fg-dim">
                                Manual
                            </span>
                        </div>
                        <div class="mt-auto pt-5">
                            <h3 class="text-[13px]! font-semibold! text-black dark:text-fg">IP address</h3>
                            <p class="mt-1 text-[11px] leading-4 text-neutral-500 dark:text-fg-faint">
                                Connect an existing server over SSH.
                            </p>
                        </div>
                    </a>
                </div>
            </x-application.settings-section>
        </div>
    @else
        <div class="application-settings-form">
            @if ($selectedType === 'hetzner')
                <livewire:server.new.by-hetzner :private_keys="$private_keys" :limit_reached="$limit_reached"
                    :selected-token-uuid="$selectedTokenUuid"
                    wire:key="new-server-hetzner-{{ $selectedTokenUuid ?? 'select' }}" />
            @elseif ($selectedType === 'vultr')
                <livewire:server.new.by-vultr :private_keys="$private_keys" :limit_reached="$limit_reached"
                    :selected-token-uuid="$selectedTokenUuid"
                    wire:key="new-server-vultr-{{ $selectedTokenUuid ?? 'select' }}" />
            @elseif ($selectedType === 'digital-ocean')
                <livewire:server.new.by-digital-ocean :private_keys="$private_keys" :limit_reached="$limit_reached"
                    :selected-token-uuid="$selectedTokenUuid"
                    wire:key="new-server-digital-ocean-{{ $selectedTokenUuid ?? 'select' }}" />
            @else
                <livewire:server.new.by-ip :private_keys="$private_keys" :limit_reached="$limit_reached"
                    key="new-server-manual" />
            @endif
        </div>
    @endif
</div>
