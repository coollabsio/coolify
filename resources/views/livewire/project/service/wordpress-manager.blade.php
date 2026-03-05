<div>
    <x-slot:title>
        WordPress Manager | Coolify
    </x-slot>
    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="[]" />

    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <div class="sub-menu-wrapper">
            <a class="sub-menu-item" target="_blank" href="{{ $service->documentation() }}"><span class="menu-item-label">Documentation</span>
                <x-external-link /></a>
            <a class='sub-menu-item' wire:current.exact="menu-item-active" {{ wireNavigate() }}
                href="{{ route('project.service.configuration', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">General</span></a>
            <a class='sub-menu-item' wire:current.exact="menu-item-active" {{ wireNavigate() }}
                href="{{ route('project.service.wordpress-manager', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">WordPress Manager</span></a>
        </div>
        <div class="w-full overflow-x-hidden">
            <div class="box-without-bg">
                <h2 class="text-xl font-bold dark:text-white mb-6">WordPress Manager</h2>
                
                @if (empty($wordpressContainers))
                    <div class="p-4 text-sm text-neutral-500">
                        No WordPress containers detected in this service.
                    </div>
                @else
                    <div class="flex flex-col gap-6">
                    <!-- Detected Containers Section -->
                    <div>
                        <h3 class="text-lg font-semibold dark:text-white mb-2">Detected WordPress Containers:</h3>
                        <ul class="list-disc list-inside space-y-1">
                            @foreach ($wordpressContainers as $container)
                                <li class="text-sm">
                                    <span class="font-medium">{{ $container['name'] }}</span>
                                    <span class="text-xs text-gray-500">({{ $container['status'] }})</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <!-- Sync URLs Section -->
                    <div class="box-without-bg-without-border dark:bg-coolgray-100 bg-white p-6">
                        <h3 class="text-lg font-semibold dark:text-white mb-4">Sincronizar URLs</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                            Reemplaza todas las URLs antiguas por las nuevas en la base de datos y regenera los archivos CSS de Elementor.
                        </p>

                        <form wire:submit="syncUrls">
                            <div class="flex flex-col gap-4">
                                <div>
                                    <label for="oldUrl" class="block text-sm font-medium mb-2">URL Antigua</label>
                                    <input type="url" id="oldUrl" wire:model="oldUrl" 
                                        class="w-full input" 
                                        placeholder="https://url-antigua.com"
                                        required>
                                </div>

                                <div>
                                    <label for="newUrl" class="block text-sm font-medium mb-2">URL Nueva</label>
                                    <input type="url" id="newUrl" wire:model="newUrl" 
                                        class="w-full input" 
                                        placeholder="https://url-nueva.com"
                                        required>
                                </div>

                                <x-forms.button type="submit" 
                                    class="bg-coollabs" 
                                    :disabled="$isProcessing">
                                    @if ($isProcessing)
                                        <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        Procesando...
                                    @else
                                        Sincronizar URLs y Regenerar CSS
                                    @endif
                                </x-forms.button>
                            </div>
                        </form>

                        @if ($output)
                            <div class="mt-6">
                                <h4 class="text-sm font-semibold dark:text-white mb-2">Salida del comando:</h4>
                                <pre class="bg-coolgray-900 text-green-400 p-4 rounded text-xs overflow-auto max-h-96">{{ $output }}</pre>
                            </div>
                        @endif
                    </div>

                    <!-- WordPress Table Prefix Section -->
                    <div class="box-without-bg-without-border dark:bg-coolgray-100 bg-white p-6">
                        <h3 class="text-lg font-semibold dark:text-white mb-4">WordPress Table Prefix</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                            Gestiona el prefijo de las tablas de WordPress. El prefijo se detecta automáticamente desde wp-config.php o desde las tablas de la base de datos.
                        </p>

                        @foreach ($wordpressContainers as $container)
                            @php
                                $prefixData = $wpPrefixes[$container['id']] ?? ['container_name' => $container['name'], 'prefix' => 'wp_'];
                                $currentPrefix = $prefixData['prefix'] ?? 'wp_';
                            @endphp
                            <div class="mb-4 p-4 border border-coolgray-300 dark:border-coolgray-600 rounded">
                                <div class="flex items-center justify-between mb-2">
                                    <div>
                                        <span class="font-medium dark:text-white">{{ $container['name'] }}</span>
                                        <span class="text-xs text-gray-500 ml-2">({{ $container['status'] }})</span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 mt-2" x-data="{ prefix: '{{ $currentPrefix }}' }">
                                    <label for="prefix_{{ $container['id'] }}" class="text-sm font-medium dark:text-white">Prefijo:</label>
                                    <input type="text" 
                                        id="prefix_{{ $container['id'] }}" 
                                        x-model="prefix"
                                        class="input w-32"
                                        pattern="[a-zA-Z0-9_]+"
                                        placeholder="wp_">
                                    <x-forms.button 
                                        x-on:click="$wire.updateWpPrefix({{ $container['id'] }}, prefix)"
                                        class="bg-coollabs"
                                        :disabled="!str($container['status'])->contains('running')">
                                        Actualizar
                                    </x-forms.button>
                                </div>
                                @if (!str($container['status'])->contains('running'))
                                    <p class="text-xs text-yellow-600 dark:text-yellow-400 mt-1">El contenedor debe estar en ejecución para actualizar el prefijo.</p>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <!-- PHP Configuration Section -->
                    <div class="box-without-bg-without-border dark:bg-coolgray-100 bg-white p-6">
                        <h3 class="text-lg font-semibold dark:text-white mb-4">Configuración PHP (php.ini)</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                            Gestiona la configuración de PHP para los contenedores WordPress. Los cambios pueden requerir reiniciar el contenedor para aplicarse completamente.
                        </p>

                        <div class="mb-6">
                            <label for="php_ini_container" class="block text-sm font-medium dark:text-white mb-2">Seleccionar Contenedor:</label>
                            <select id="php_ini_container" 
                                wire:model.live="selectedContainerForPhpIni"
                                wire:change="loadPhpIniSettings"
                                class="input w-full">
                                <option value="">-- Selecciona un contenedor --</option>
                                @foreach ($wordpressContainers as $container)
                                    <option value="{{ $container['id'] }}" 
                                        @if (!str($container['status'])->contains('running')) disabled @endif>
                                        {{ $container['name'] }} 
                                        @if (!str($container['status'])->contains('running'))
                                            (No está en ejecución)
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        @if ($selectedContainerForPhpIni && !empty($phpIniSettings))
                            <div class="space-y-4">
                                @if ($isLoadingPhpIni)
                                    <div class="flex items-center justify-center p-8">
                                        <svg class="animate-spin h-8 w-8 text-coollabs" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        <span class="ml-2 text-gray-600 dark:text-gray-400">Cargando configuración...</span>
                                    </div>
                                @else
                                    <div class="space-y-4" 
                                        x-data="{ 
                                            settings: @js($phpIniSettings),
                                            updateSetting(setting, value) {
                                                if (!value || value.trim() === '') {
                                                    return;
                                                }
                                                $wire.updatePhpIniSetting(setting, value.trim());
                                            }
                                        }">
                                        @foreach ($phpIniSettings as $setting => $value)
                                            <div class="p-4 border border-coolgray-300 dark:border-coolgray-600 rounded bg-white dark:bg-coolgray-800">
                                                <label for="php_setting_{{ $setting }}" class="block text-sm font-medium dark:text-white mb-3">
                                                    {{ str_replace('_', ' ', ucwords($setting, '_')) }}
                                                </label>
                                                <div class="flex flex-col gap-3">
                                                    <input type="text" 
                                                        id="php_setting_{{ $setting }}"
                                                        value="{{ $value }}"
                                                        x-model="settings['{{ $setting }}']"
                                                        @keydown.enter="updateSetting('{{ $setting }}', settings['{{ $setting }}'])"
                                                        class="w-full bg-white dark:bg-coolgray-900 text-gray-900 dark:text-gray-100 border-2 border-coolgray-300 dark:border-coolgray-600 px-4 py-3 rounded text-base font-mono focus:border-coollabs focus:ring-2 focus:ring-coollabs/20"
                                                        placeholder="Ej: 200M, 512M, 60, etc."
                                                        style="min-height: 48px; font-size: 16px;">
                                                    <x-forms.button 
                                                        x-on:click="updateSetting('{{ $setting }}', settings['{{ $setting }}'])"
                                                        class="bg-coollabs whitespace-nowrap w-full sm:w-auto self-start">
                                                        Actualizar
                                                    </x-forms.button>
                                                </div>
                                                <p class="text-xs text-gray-500 dark:text-gray-400 mt-3">
                                                    Valor actual: <span class="font-mono font-semibold">{{ $value }}</span>
                                                </p>
                                            </div>
                                        @endforeach
                                    </div>

                                    <div class="mt-6 p-3 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded">
                                        <p class="text-sm text-yellow-800 dark:text-yellow-200">
                                            <strong>Nota:</strong> Algunos cambios pueden requerir reiniciar el contenedor para aplicarse completamente. 
                                            Los valores de memoria y tamaño de archivo generalmente requieren reinicio.
                                        </p>
                                    </div>
                                @endif
                            </div>
                        @elseif ($selectedContainerForPhpIni && empty($phpIniSettings) && !$isLoadingPhpIni)
                            <div class="p-4 text-sm text-gray-600 dark:text-gray-400">
                                No se pudieron cargar las configuraciones de PHP. Asegúrate de que el contenedor esté en ejecución.
                            </div>
                        @endif
                    </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
