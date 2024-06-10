<div>
    <x-slot:title>
        Settings | Coolify
    </x-slot>
    <x-settings.navbar />
    <div x-data="{ activeTab: window.location.hash ? window.location.hash.substring(1) : 'general' }" class="flex flex-col h-full gap-8 pt-1 sm:flex-row">
        <div class="flex gap-6 overflow-x-scroll sm:gap-2 sm:overflow-x-hidden scrollbar sm:flex-col whitespace-nowrap">
            <a class="menu-item" :class="activeTab === 'general' && 'menu-item-active'"
                @click.prevent="activeTab = 'general'; window.location.hash = 'general'" href="#">General</a>
            <a class="menu-item" :class="activeTab === 'backup' && 'menu-item-active'"
                @click.prevent="activeTab = 'backup'; window.location.hash = 'backup'" href="#">Instance Backup</a>
            <a class="menu-item" :class="activeTab === 'smtp' && 'menu-item-active'"
                @click.prevent="activeTab = 'smtp'; window.location.hash = 'smtp'" href="#">Transactional
                Email</a>
            <a class="menu-item" :class="activeTab === 'auth' && 'menu-item-active'"
                @click.prevent="activeTab = 'auth'; window.location.hash = 'auth'" href="#">Authentication
                (OAuth)</a>
        </div>
        <div class="w-full">
            <div x-cloak x-show="activeTab === 'general'" class="h-full">
                <livewire:settings.configuration :settings="$settings" />
            </div>
            <div x-cloak x-show="activeTab === 'backup'" class="h-full">
                <livewire:settings.backup :settings="$settings" :database="$database" :s3s="$s3s" />
            </div>
            <div x-cloak x-show="activeTab === 'smtp'" class="h-full">
                <livewire:settings.email :settings="$settings" />
            </div>
            <div x-cloak x-show="activeTab === 'auth'" class="h-full">
                <livewire:settings.auth />
            </div>
        </div>
    </div>
</div>
