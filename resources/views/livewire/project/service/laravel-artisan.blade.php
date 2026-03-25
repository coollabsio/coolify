<div>
    <x-slot:title>
        Artisan Commands | Coolify
    </x-slot>

    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="[]" />

    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <div class="sub-menu-wrapper">
            <a class="sub-menu-item" target="_blank" href="{{ $service->documentation() }}"><span class="menu-item-label">Documentation</span>
                <x-external-link /></a>
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.configuration', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">General</span></a>
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.laravel-manager', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Laravel Manager</span></a>
            <a class='sub-menu-item' wire:current.exact="menu-item-active" {{ wireNavigate() }}
                href="{{ route('project.service.laravel-artisan', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Artisan Commands</span></a>
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.laravel-cron', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Laravel Cron</span></a>
        </div>

        <div class="w-full overflow-x-hidden">
            <div class="box-without-bg">
                <h2 class="text-xl font-bold dark:text-white mb-6">Artisan Commands</h2>

                @if (empty($laravelContainers))
                    <div class="p-4 text-sm text-neutral-500">
                        No Laravel containers detected in this service.
                    </div>
                @else
                    <div class="box-without-bg-without-border dark:bg-coolgray-100 bg-white p-6 w-full max-w-none">
                        <div class="relative w-full max-w-none">
                            <label class="block text-sm font-medium dark:text-white mb-2">Comando:</label>

                            <div class="flex items-start w-full max-w-none gap-0">
                                <div class="relative flex-1 min-w-0">
                                    <input
                                        type="text"
                                        wire:model.live="selectedCommand"
                                        wire:focus="showPopularCommands"
                                        class="input w-full min-w-0"
                                        placeholder="Ej: migrate --force"
                                    />

                                    @if (! $isLoadingCommands && ! empty($filteredArtisanCommands))
                                        <div class="absolute z-20 left-0 right-0 mt-1 bg-white dark:bg-coolgray-800 border border-coolgray-300 dark:border-coolgray-600 rounded shadow-lg max-h-[420px] overflow-hidden text-gray-900 dark:text-gray-100">
                                            @foreach ($filteredArtisanCommands as $cmd)
                                                <button
                                                    type="button"
                                                    class="w-full px-3 py-2 hover:bg-neutral-100 dark:hover:bg-coolgray-700 flex items-center gap-2 text-left text-gray-900 dark:text-gray-100 hover:text-gray-900 dark:hover:text-gray-100"
                                                    wire:click="selectCommand(@js($cmd['name']))"
                                                >
                                                    <span class="font-mono text-sm whitespace-nowrap text-black dark:text-gray-100">{{ $cmd['name'] }}</span>
                                                    @if (! empty($cmd['description']))
                                                        <span
                                                            class="text-xs text-gray-900 dark:text-gray-300 truncate max-w-96"
                                                            title="{{ $cmd['description'] }}"
                                                        >
                                                            - {{ $cmd['description'] }}
                                                        </span>
                                                    @endif
                                                </button>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>

                                <div class="shrink-0">
                                    <x-forms.button
                                        wire:click="run"
                                        wire:loading.attr="disabled"
                                        wire:target="run"
                                        class="bg-coollabs h-10 px-4"
                                    >
                                        Ejecutar
                                    </x-forms.button>
                                </div>
                            </div>

                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Se ejecuta como:
                                <span class="font-mono">{{ 'php /var/www/html/artisan '.trim((string) $selectedCommand) }}</span>
                            </div>
                        </div>

                        <div class="mt-6">
                            <label class="block text-sm font-medium dark:text-white mb-2">Salida:</label>
                            <pre class="w-full max-w-none whitespace-pre-wrap break-words bg-white dark:bg-coolgray-900 text-gray-900 dark:text-gray-100 border border-coolgray-300 dark:border-coolgray-600 px-4 py-3 rounded text-sm font-mono min-h-80 max-h-[60vh] overflow-auto">{{ $output }}</pre>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

