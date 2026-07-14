<div class="w-full">
    @if (!$selectedType)
        <div class="flex flex-col items-center justify-center gap-3 sm:gap-6 sm:min-h-[calc(100vh-12rem)]">
            <div class="mx-auto flex w-full max-w-7xl flex-col gap-3 sm:grid sm:grid-cols-2 sm:gap-6 xl:grid-cols-4">
                @can('viewAny', App\Models\CloudProviderToken::class)
                    <a href="{{ route('server.create.type', ['type' => 'hetzner']) }}" aria-label="Choose Hetzner"
                        class="box-without-bg flex-col gap-3 sm:gap-6 p-3 sm:p-6 h-full cursor-pointer transition-all duration-200 hover:border-coollabs dark:hover:border-warning focus-visible:border-coollabs dark:focus-visible:border-warning focus-visible:outline-none"
                        {{ wireNavigate() }}>
                        <div class="flex flex-col gap-2 sm:gap-4 text-left h-full">
                            <div class="flex items-center justify-between">
                                <svg class="size-9 sm:size-14" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
                                    <rect width="200" height="200" fill="#D50C2D" rx="8" />
                                    <path d="M40 40 H60 V90 H140 V40 H160 V160 H140 V110 H60 V160 H40 Z"
                                        fill="white" />
                                </svg>
                                <span
                                    class="px-1.5 py-0.5 text-[10px] sm:px-2 sm:py-1 sm:text-xs font-bold uppercase tracking-wide bg-coollabs/10 dark:bg-warning/20 text-coollabs dark:text-warning rounded">
                                    Provider
                                </span>
                            </div>
                            <div>
                                <h3 class="text-sm sm:text-xl font-bold mb-1 sm:mb-2">Hetzner</h3>
                                <p class="hidden sm:block text-sm dark:text-neutral-400">
                                    Deploy servers directly from your Hetzner Cloud account.
                                </p>
                            </div>
                        </div>
                    </a>

                    <a href="{{ route('server.create.type', ['type' => 'vultr']) }}" aria-label="Choose Vultr"
                        class="box-without-bg flex-col gap-3 sm:gap-6 p-3 sm:p-6 h-full cursor-pointer transition-all duration-200 hover:border-coollabs dark:hover:border-warning focus-visible:border-coollabs dark:focus-visible:border-warning focus-visible:outline-none"
                        {{ wireNavigate() }}>
                        <div class="flex flex-col gap-2 sm:gap-4 text-left h-full">
                            <div class="flex items-center justify-between">
                                <svg class="size-9 sm:size-14" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
                                    <rect width="200" height="200" fill="#007BFC" rx="8" />
                                    <path d="M42 46 H73 L100 127 L127 46 H158 L114 154 H86 Z" fill="white" />
                                </svg>
                                <span
                                    class="px-1.5 py-0.5 text-[10px] sm:px-2 sm:py-1 sm:text-xs font-bold uppercase tracking-wide bg-coollabs/10 dark:bg-warning/20 text-coollabs dark:text-warning rounded">
                                    Provider
                                </span>
                            </div>
                            <div>
                                <h3 class="text-sm sm:text-xl font-bold mb-1 sm:mb-2">Vultr</h3>
                                <p class="hidden sm:block text-sm dark:text-neutral-400">
                                    Deploy servers directly from your Vultr account.
                                </p>
                            </div>
                        </div>
                    </a>

                    <a href="{{ route('server.create.type', ['type' => 'digital-ocean']) }}"
                        aria-label="Choose DigitalOcean"
                        class="box-without-bg flex-col gap-3 sm:gap-6 p-3 sm:p-6 h-full cursor-pointer transition-all duration-200 hover:border-coollabs dark:hover:border-warning focus-visible:border-coollabs dark:focus-visible:border-warning focus-visible:outline-none"
                        {{ wireNavigate() }}>
                        <div class="flex flex-col gap-2 sm:gap-4 text-left h-full">
                            <div class="flex items-center justify-between">
                                <x-digital-ocean-icon class="size-9 sm:size-14" />
                                <span
                                    class="px-1.5 py-0.5 text-[10px] sm:px-2 sm:py-1 sm:text-xs font-bold uppercase tracking-wide bg-coollabs/10 dark:bg-warning/20 text-coollabs dark:text-warning rounded">
                                    Provider
                                </span>
                            </div>
                            <div>
                                <h3 class="text-sm sm:text-xl font-bold mb-1 sm:mb-2">DigitalOcean</h3>
                                <p class="hidden sm:block text-sm dark:text-neutral-400">
                                    Deploy droplets directly from your DigitalOcean account.
                                </p>
                            </div>
                        </div>
                    </a>
                @endcan

                <a href="{{ route('server.create.type', ['type' => 'manual']) }}" aria-label="Choose Manual"
                    class="box-without-bg flex-col gap-3 sm:gap-6 p-3 sm:p-6 h-full cursor-pointer transition-all duration-200 hover:border-coollabs dark:hover:border-warning focus-visible:border-coollabs dark:focus-visible:border-warning focus-visible:outline-none"
                    {{ wireNavigate() }}>
                    <div class="flex flex-col gap-2 sm:gap-4 text-left h-full">
                        <div class="flex items-center justify-between">
                            <svg class="size-9 sm:size-14" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z" />
                            </svg>
                            <span
                                class="px-1.5 py-0.5 text-[10px] sm:px-2 sm:py-1 sm:text-xs font-bold uppercase tracking-wide bg-neutral-100 dark:bg-coolgray-300 dark:text-neutral-400 rounded">
                                Manual
                            </span>
                        </div>
                        <div>
                            <h3 class="text-sm sm:text-xl font-bold mb-1 sm:mb-2">Manual</h3>
                            <p class="hidden sm:block text-sm dark:text-neutral-400">
                                Add any reachable server by IP address or domain.
                            </p>
                        </div>
                    </div>
                </a>
            </div>
        </div>
    @else
        <div class="flex flex-col gap-4">
            @if ($selectedType === 'hetzner')
                <livewire:server.new.by-hetzner :private_keys="$private_keys" :limit_reached="$limit_reached"
                    :selected-token-uuid="$selectedTokenUuid" wire:key="new-server-hetzner-{{ $selectedTokenUuid ?? 'select' }}" />
            @elseif ($selectedType === 'vultr')
                <livewire:server.new.by-vultr :private_keys="$private_keys" :limit_reached="$limit_reached"
                    :selected-token-uuid="$selectedTokenUuid" wire:key="new-server-vultr-{{ $selectedTokenUuid ?? 'select' }}" />
            @elseif ($selectedType === 'digital-ocean')
                <livewire:server.new.by-digital-ocean :private_keys="$private_keys" :limit_reached="$limit_reached"
                    :selected-token-uuid="$selectedTokenUuid" wire:key="new-server-digital-ocean-{{ $selectedTokenUuid ?? 'select' }}" />
            @else
                <livewire:server.new.by-ip :private_keys="$private_keys" :limit_reached="$limit_reached"
                    key="new-server-manual" />
            @endif
        </div>
    @endif
</div>
