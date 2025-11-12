<div>
    <x-slot:title>
        Terminal | Coolify
        </x-slot>
        <h1>Terminal</h1>
        <div class="flex gap-2 items-end subtitle">
            <div>Execute commands on your servers and containers without leaving the browser.</div>
            <x-helper
                helper="If you're having trouble connecting to your server, make sure that the port is open.<br><br><a class='underline' href='https://coolify.io/docs/knowledge-base/server/firewall/#terminal' target='_blank'>Documentation</a>"></x-helper>
        </div>
        <div x-init="$wire.loadContainers()">
            @if ($isLoadingContainers)
                <div class="pt-1">
                    <x-loading text="Loading servers and containers..." />
                </div>
            @else
                @if ($servers->count() > 0)
                    <div class="flex flex-col gap-2 justify-center xl:items-end xl:flex-row">
                        <form class="flex flex-col gap-2 justify-center xl:items-end xl:flex-row flex-1"
                            wire:submit="$dispatchSelf('connectToContainer')">
                            <x-forms.select id="selected_uuid" required wire:model.live="selected_uuid">
                                <option value="default">Select a server or container</option>
                                @foreach ($servers as $server)
                                    <option value="{{ $server->uuid }}">{{ $server->name }}</option>
                                    @foreach ($containers as $container)
                                        @if ($container['server_uuid'] == $server->uuid)
                                            <option value="{{ $container['uuid'] }}">
                                                {{ $server->name }} -> {{ $container['name'] }}
                                            </option>
                                        @endif
                                    @endforeach
                                @endforeach
                            </x-forms.select>
                            <x-forms.button type="submit">Connect</x-forms.button>
                        </form>
                        @if ($selected_uuid !== 'default')
                            <x-forms.button wire:click="openImportModal">Import File</x-forms.button>
                        @endif
                    </div>
                @else
                    <div>No servers with terminal access found.</div>
                @endif
            @endif
            <livewire:project.shared.terminal />
        </div>

        @if ($showImportModal && $selected_uuid !== 'default')
            <div class="fixed top-0 left-0 z-99 flex items-center justify-center w-screen h-screen p-4"
                @keydown.window.escape="$wire.closeImportModal()">
                <div class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs"
                    wire:click="closeImportModal">
                </div>
                <div class="relative w-full border rounded-sm drop-shadow-sm min-w-full lg:min-w-[36rem] max-w-fit max-h-[calc(100vh-2rem)] bg-white border-neutral-200 dark:bg-base dark:border-coolgray-300 flex flex-col">
                    <div class="flex items-center justify-between py-6 px-6 shrink-0">
                        <h3 class="text-2xl font-bold">Import File to Terminal</h3>
                        <button wire:click="closeImportModal"
                            class="absolute top-0 right-0 flex items-center justify-center w-8 h-8 mt-5 mr-5 rounded-full dark:text-white hover:bg-neutral-100 dark:hover:bg-coolgray-300">
                            <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                    <div class="relative flex items-center justify-center w-auto overflow-y-auto px-6 pb-6">
                        @php
                            $targetName = $this->getTargetName();
                            $serverUuid = $this->getServerUuid();
                        @endphp
                        <livewire:terminal.file-import 
                            :selectedUuid="$selected_uuid" 
                            :targetName="$targetName"
                            :selectedServerUuid="$serverUuid"
                            :key="'file-import-'.$selected_uuid" 
                        />
                    </div>
                </div>
            </div>
        @endif
</div>