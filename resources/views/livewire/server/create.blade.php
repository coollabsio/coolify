<div class="w-full">
    <div class="flex flex-col gap-4">
        @can('viewAny', App\Models\CloudProviderToken::class)
            <div>
                <div class="flex gap-2 flex-wrap">
                    <x-modal-input title="Connect a Hetzner Server">
                        <x-slot:button-title>
                            <div class="flex items-center gap-2">
                                <svg class="w-5 h-5" viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">
                                    <rect width="200" height="200" fill="#D50C2D" rx="8" />
                                    <path d="M40 40 H60 V90 H140 V40 H160 V160 H140 V110 H60 V160 H40 Z" fill="white" />
                                </svg>
                                <span>Hetzner</span>
                            </div>
                        </x-slot:button-title>
                        <livewire:server.new.by-hetzner :private_keys="$private_keys" :limit_reached="$limit_reached" />
                    </x-modal-input>
                </div>
            </div>

            <div class="border-t dark:border-coolgray-300 my-4"></div>
        @endcan

        <div>
            <h3 class="pb-2">Add Server by IP Address</h3>
            <livewire:server.new.by-ip :private_keys="$private_keys" :limit_reached="$limit_reached" />
        </div>
    </div>
</div>
