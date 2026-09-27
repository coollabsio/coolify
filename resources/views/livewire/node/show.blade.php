<div>
    <x-slot:title>
        {{ data_get_str($node, 'name')->limit(24) }} | Node | Coolify
    </x-slot>

    <x-node.navbar :node="$node" />

    <div
        class="node-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-node.sidebar :node="$node" :activeMenu="$section" />

        <div class="flex w-full min-w-0 flex-col gap-6">
            @include('livewire.node.partials.'.$section)
        </div>
    </div>
</div>
