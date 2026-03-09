<div>
    <x-slot:title>
        Laravel Manager | Coolify
    </x-slot>
    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="[]" />
    
    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.on('error', (data) => {
                const message = Array.isArray(data) ? data[0] : data;
                console.error('[Laravel Manager Error]', message);
            });
            
            Livewire.on('success', (data) => {
                const message = Array.isArray(data) ? data[0] : data;
                console.log('[Laravel Manager Success]', message);
            });
            
            Livewire.on('warning', (data) => {
                const message = Array.isArray(data) ? data[0] : data;
                console.warn('[Laravel Manager Warning]', message);
            });
        });
    </script>

    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <div class="sub-menu-wrapper">
            <a class="sub-menu-item" target="_blank" href="{{ $service->documentation() }}"><span class="menu-item-label">Documentation</span>
                <x-external-link /></a>
            <a class='sub-menu-item' wire:current.exact="menu-item-active" {{ wireNavigate() }}
                href="{{ route('project.service.configuration', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">General</span></a>
            <a class='sub-menu-item' wire:current.exact="menu-item-active" {{ wireNavigate() }}
                href="{{ route('project.service.laravel-manager', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Laravel Manager</span></a>
        </div>
        <div class="w-full overflow-x-hidden">
            <div class="box-without-bg">
                <h2 class="text-xl font-bold dark:text-white mb-6">Laravel Manager</h2>
                
                @if (empty($laravelContainers))
                    <div class="p-4 text-sm text-neutral-500">
                        No Laravel containers detected in this service.
                    </div>
                @else
                    <div class="flex flex-col gap-6">
                        <!-- Detected Containers Section -->
                        <div>
                            <h3 class="text-lg font-semibold dark:text-white mb-2">Detected Laravel Containers:</h3>
                            <ul class="list-disc list-inside space-y-1">
                                @foreach ($laravelContainers as $container)
                                    <li class="text-sm">
                                        <span class="font-medium">{{ $container['name'] }}</span>
                                        <span class="text-xs text-gray-500">({{ $container['status'] }})</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>

                        <!-- Environment Variables Section -->
                        <div class="box-without-bg-without-border dark:bg-coolgray-100 bg-white p-6">
                            <h3 class="text-lg font-semibold dark:text-white mb-4">Configuración .env</h3>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                                Gestiona las variables de entorno de Laravel desde el archivo .env.
                            </p>

                            <div class="mb-6">
                                <label for="env_container" class="block text-sm font-medium dark:text-white mb-2">Seleccionar Contenedor:</label>
                                <select id="env_container" 
                                    wire:model.live="selectedContainerForEnv"
                                    wire:change="loadEnvVariables"
                                    class="input w-full">
                                    <option value="">-- Selecciona un contenedor --</option>
                                    @foreach ($laravelContainers as $container)
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

                            @if ($selectedContainerForEnv)
                                @if ($isLoadingEnv)
                                    <div class="flex items-center justify-center p-8">
                                        <svg class="animate-spin h-8 w-8 text-coollabs" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        <span class="ml-2 text-gray-600 dark:text-gray-400">Cargando archivo .env...</span>
                                    </div>
                                @else
                                    <div class="space-y-4">
                                        <div class="p-4 border border-coolgray-300 dark:border-coolgray-600 rounded bg-white dark:bg-coolgray-800">
                                            <label for="env_content" class="block text-sm font-medium dark:text-white mb-3">
                                                Contenido del archivo .env
                                            </label>
                                            <textarea 
                                                id="env_content"
                                                wire:model="envContent"
                                                rows="20"
                                                class="w-full bg-white dark:bg-coolgray-900 text-gray-900 dark:text-gray-100 border-2 border-coolgray-300 dark:border-coolgray-600 px-4 py-3 rounded text-sm font-mono focus:border-coollabs focus:ring-2 focus:ring-coollabs/20 resize-y"
                                                placeholder="APP_NAME=Laravel&#10;APP_ENV=production&#10;APP_KEY=&#10;APP_DEBUG=false&#10;APP_URL=https://example.com&#10;&#10;DB_CONNECTION=mysql&#10;DB_HOST=127.0.0.1&#10;DB_PORT=3306&#10;DB_DATABASE=laravel&#10;DB_USERNAME=root&#10;DB_PASSWORD="
                                                style="min-height: 400px; font-family: 'Courier New', monospace; line-height: 1.6;"></textarea>
                                            <div class="mt-4 flex gap-2">
                                                <x-forms.button 
                                                    wire:click="saveEnvFile"
                                                    class="bg-coollabs">
                                                    Guardar Cambios
                                                </x-forms.button>
                                                <x-forms.button 
                                                    wire:click="loadEnvVariables"
                                                    class="bg-gray-500 hover:bg-gray-600">
                                                    Recargar
                                                </x-forms.button>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            @endif
                        </div>

                        <!-- PHP Configuration Section -->
                        <div class="box-without-bg-without-border dark:bg-coolgray-100 bg-white p-6">
                            <h3 class="text-lg font-semibold dark:text-white mb-4">Configuración PHP (php.ini)</h3>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                                Visualiza y gestiona la configuración de PHP para los contenedores Laravel.
                            </p>

                            <div class="mb-6">
                                <label for="php_ini_container" class="block text-sm font-medium dark:text-white mb-2">Seleccionar Contenedor:</label>
                                <select id="php_ini_container" 
                                    wire:model.live="selectedContainerForPhpIni"
                                    wire:change="loadPhpIniSettings"
                                    class="input w-full">
                                    <option value="">-- Selecciona un contenedor --</option>
                                    @foreach ($laravelContainers as $container)
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
                                        @foreach ($phpIniSettings as $setting => $value)
                                            <div class="p-4 border border-coolgray-300 dark:border-coolgray-600 rounded bg-white dark:bg-coolgray-800">
                                                <div class="flex items-center justify-between">
                                                    <div>
                                                        <span class="font-medium dark:text-white">{{ str_replace('_', ' ', ucwords($setting, '_')) }}</span>
                                                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                                            Valor actual: <span class="font-mono font-semibold">{{ $value }}</span>
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    @endif
                                </div>
                            @elseif ($selectedContainerForPhpIni && empty($phpIniSettings) && !$isLoadingPhpIni)
                                <div class="p-4 text-sm text-gray-600 dark:text-gray-400">
                                    No se pudieron cargar las configuraciones de PHP. Asegúrate de que el contenedor esté en ejecución.
                                </div>
                            @endif
                        </div>

                        <!-- Scheduler/Cron Section -->
                        <div class="box-without-bg-without-border dark:bg-coolgray-100 bg-white p-6">
                            <h3 class="text-lg font-semibold dark:text-white mb-4">Laravel Scheduler (Cron)</h3>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                                Gestiona el scheduler de Laravel que ejecuta las tareas programadas.
                            </p>

                            <div class="mb-6">
                                <label for="cron_container" class="block text-sm font-medium dark:text-white mb-2">Seleccionar Contenedor:</label>
                                <select id="cron_container" 
                                    wire:model.live="selectedContainerForCron"
                                    wire:change="checkSchedulerStatus"
                                    class="input w-full">
                                    <option value="">-- Selecciona un contenedor --</option>
                                    @foreach ($laravelContainers as $container)
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

                            @if ($selectedContainerForCron)
                                <div class="space-y-4">
                                    @if ($isLoadingCron)
                                        <div class="flex items-center justify-center p-8">
                                            <svg class="animate-spin h-8 w-8 text-coollabs" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                            </svg>
                                            <span class="ml-2 text-gray-600 dark:text-gray-400">Verificando estado...</span>
                                        </div>
                                    @else
                                        <div class="p-4 border border-coolgray-300 dark:border-coolgray-600 rounded bg-white dark:bg-coolgray-800">
                                            <div class="flex items-center justify-between mb-4">
                                                <div>
                                                    <span class="font-medium dark:text-white">Estado del Scheduler:</span>
                                                    <span class="ml-2 px-2 py-1 rounded text-sm {{ $isSchedulerEnabled ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' }}">
                                                        {{ $schedulerStatus }}
                                                    </span>
                                                </div>
                                                <x-forms.button 
                                                    wire:click="toggleScheduler"
                                                    class="{{ $isSchedulerEnabled ? 'bg-red-500 hover:bg-red-600' : 'bg-green-500 hover:bg-green-600' }}">
                                                    {{ $isSchedulerEnabled ? 'Detener' : 'Iniciar' }}
                                                </x-forms.button>
                                            </div>
                                            
                                            <div class="flex gap-2">
                                                <x-forms.button 
                                                    wire:click="checkSchedulerStatus"
                                                    class="bg-coollabs">
                                                    Verificar Estado
                                                </x-forms.button>
                                                <x-forms.button 
                                                    wire:click="runScheduler"
                                                    class="bg-blue-500 hover:bg-blue-600">
                                                    Ejecutar Ahora
                                                </x-forms.button>
                                            </div>

                                            @if ($schedulerOutput)
                                                <div class="mt-4">
                                                    <h4 class="text-sm font-semibold dark:text-white mb-2">Salida:</h4>
                                                    <pre class="bg-coolgray-900 text-green-400 p-4 rounded text-xs overflow-auto max-h-96">{{ $schedulerOutput }}</pre>
                                                </div>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
