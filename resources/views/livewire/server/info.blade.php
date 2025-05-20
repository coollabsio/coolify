<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Server Info | Coolify
    </x-slot>
    <x-server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="info" />
        <div class="w-full">
            <div class="flex justify-between items-center">
                <h2>Server Information</h2>
                <x-forms.button wire:click="collectServerInfo" wire:loading.attr="disabled" wire:target="collectServerInfo">
                    <span wire:loading.remove wire:target="collectServerInfo">Refresh Server Info</span>
                    <span wire:loading wire:target="collectServerInfo">Collecting...</span>
                </x-forms.button>
            </div>
            <div class="mt-4">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="p-4 border rounded-lg dark:border-coolgray-300">
                        <h3 class="mb-2">CPU Information</h3>
                        <div class="flex flex-col gap-2">
                            <div class="flex justify-between">
                                <span>Model:</span>
                                <span>{{ $server->settings->cpu_model ?? 'Not available' }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Cores:</span>
                                <span>{{ $server->settings->cpu_cores ?? 'Not available' }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Speed:</span>
                                <span>{{ $server->settings->cpu_speed ?? 'Not available' }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="p-4 border rounded-lg dark:border-coolgray-300">
                        <h3 class="mb-2">Memory Information</h3>
                        <div class="flex flex-col gap-2">
                            <div class="flex justify-between">
                                <span>Total RAM:</span>
                                <span>{{ $server->settings->memory_total ?? 'Not available' }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>RAM Speed:</span>
                                <span>{{ $server->settings->memory_speed ?? 'Not available' }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Swap:</span>
                                <span>{{ $server->settings->swap_total ?? 'Not available' }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="p-4 border rounded-lg dark:border-coolgray-300">
                        <h3 class="mb-2">Disk Information</h3>
                        <div class="flex flex-col gap-2">
                            <div class="flex justify-between">
                                <span>Total Space:</span>
                                <span>{{ $server->settings->disk_total ?? 'Not available' }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Used Space:</span>
                                <span>{{ $server->settings->disk_used ?? 'Not available' }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Free Space:</span>
                                <span>{{ $server->settings->disk_free ?? 'Not available' }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="p-4 border rounded-lg dark:border-coolgray-300">
                        <h3 class="mb-2">GPU Information</h3>
                        <div class="flex flex-col gap-2">
                            <div class="flex justify-between">
                                <span>Model:</span>
                                <span>{{ $server->settings->gpu_model ?? 'Not available' }}</span>
                            </div>
                            <div class="flex justify-between">
                                <span>Memory:</span>
                                <span>{{ $server->settings->gpu_memory ?? 'Not available' }}</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="mt-4 p-4 border rounded-lg dark:border-coolgray-300">
                    <h3 class="mb-2">Operating System Information</h3>
                    <div class="flex flex-col gap-2">
                        <div class="flex justify-between">
                            <span>OS:</span>
                            <span>{{ $server->settings->os_name ?? 'Not available' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Version:</span>
                            <span>{{ $server->settings->os_version ?? 'Not available' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Kernel:</span>
                            <span>{{ $server->settings->kernel_version ?? 'Not available' }}</span>
                        </div>
                        <div class="flex justify-between">
                            <span>Architecture:</span>
                            <span>{{ $server->settings->architecture ?? 'Not available' }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
