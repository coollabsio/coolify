<x-layout>
    <h1>New Source</h1>
    <div class="subtitle ">Add source providers for your applications.</div>
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'github' }">
        {{-- <div class="flex justify-center h-full gap-2 pb-6">
            <a class="flex items-center justify-center w-1/2 p-2 transition-colors rounded-none min-h-12 bg-coolgray-200 hover:bg-coollabs-100 hover:dark:text-white hover:no-underline"
                :class="activeTab === 'github' && 'bg-coollabs dark:text-white'"
                @click.prevent="activeTab = 'github'; window.location.hash = 'github'" href="#">GitHub
            </a>
            <a class="flex items-center justify-center w-1/2 p-2 transition-colors rounded-none min-h-12 bg-coolgray-200 hover:bg-coollabs-100 hover:dark:text-white hover:no-underline"
                :class="activeTab === 'gitlab' && 'bg-coollabs dark:text-white'"
                @click.prevent="activeTab = 'gitlab'; window.location.hash = 'gitlab'" href="#">GitLab
            </a>
        </div> --}}
        <div x-cloak x-show="activeTab === 'github'" class="h-full">
            <livewire:source.github.create />
        </div>
        {{-- <div x-cloak x-show="activeTab === 'gitlab'" class="h-full">
            WIP
        </div> --}}
    </div>
</x-layout>
