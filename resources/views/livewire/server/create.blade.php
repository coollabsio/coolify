<div class="w-full">
    <div class="flex flex-col gap-4">
        @can('viewAny', App\Models\CloudProviderToken::class)
            <div>
                <h3 class="pb-2">Add Server from Cloud Provider</h3>
                <div class="flex gap-2 flex-wrap">
                    <x-modal-input
                        buttonTitle="+ Hetzner"
                        title="Connect to Hetzner">
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
