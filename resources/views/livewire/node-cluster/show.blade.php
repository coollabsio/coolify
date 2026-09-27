@php
    $sectionTitles = [
        'general' => 'General',
        'nodes' => 'Nodes',
        'firewall' => 'Firewall',
        'advanced' => 'Advanced',
        'danger' => 'Danger',
    ];
@endphp

<div>
    <x-slot:title>{{ str($cluster->name)->limit(24) }} > {{ $sectionTitles[$section] ?? 'General' }} | Cluster | Coolify</x-slot>

    <x-node-cluster.navbar :cluster="$cluster" />

    <div
        class="node-cluster-settings-workspace application-settings-workspace mt-4 grid w-full max-w-none min-w-0 gap-8 lg:mt-0 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
        <x-node-cluster.sidebar :cluster="$cluster" :activeMenu="$section" :nodesNeedAttention="$nodesNeedAttention" />

        <div class="application-settings-form flex w-full min-w-0 flex-col gap-6">
            @include('livewire.node-cluster.sections.'.$section)
        </div>
    </div>
</div>
