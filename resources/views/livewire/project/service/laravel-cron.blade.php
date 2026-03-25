<div>
    <x-slot:title>
        Laravel Cron | Coolify
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
            <a class='sub-menu-item' {{ wireNavigate() }}
                href="{{ route('project.service.laravel-artisan', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Artisan Commands</span></a>
            <a class='sub-menu-item' wire:current.exact="menu-item-active" {{ wireNavigate() }}
                href="{{ route('project.service.laravel-cron', ['project_uuid' => $parameters['project_uuid'], 'environment_uuid' => $parameters['environment_uuid'], 'service_uuid' => $service->uuid]) }}"><span class="menu-item-label">Laravel Cron</span></a>
        </div>

        <div class="w-full overflow-x-hidden">
            <div class="box-without-bg">
                <h2 class="text-xl font-bold dark:text-white mb-6">Laravel Scheduler (Cron)</h2>

                @if (empty($laravelContainers))
                    <div class="p-4 text-sm text-neutral-500">
                        No Laravel containers detected in this service.
                    </div>
                @else
                    <div class="box-without-bg-without-border dark:bg-coolgray-100 bg-white p-6">
                        <div class="mb-6">
                            <label class="block text-sm font-medium dark:text-white mb-2">Seleccionar Contenedor:</label>
                            <select
                                wire:model.live="selectedContainerForCron"
                                wire:change="checkSchedulerStatus"
                                class="input w-full"
                            >
                                <option value="">-- Selecciona un contenedor --</option>
                                @foreach ($laravelContainers as $container)
                                    <option value="{{ $container['id'] }}" @if (!str($container['status'])->contains('running')) disabled @endif>
                                        {{ $container['name'] }}
                                        @if (!str($container['status'])->contains('running'))
                                            (No está en ejecución)
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        @if ($selectedContainerForCron)
                            @if ($isLoadingCron)
                                <div class="flex items-center justify-center p-8">
                                    <span class="text-gray-600 dark:text-gray-400">Verificando estado...</span>
                                </div>
                            @else
                                <div class="p-4 border border-coolgray-300 dark:border-coolgray-600 rounded bg-white dark:bg-coolgray-800">
                                    <div class="flex items-center justify-between mb-4">
                                        <div>
                                            <div class="font-medium dark:text-white">Estado: <span class="font-mono">{{ $schedulerStatus }}</span></div>
                                        </div>
                                        <div class="flex gap-2">
                                            <x-forms.button wire:click="toggleScheduler" class="bg-coollabs">
                                                {{ $isSchedulerEnabled ? 'Detener' : 'Iniciar' }}
                                            </x-forms.button>
                                            <x-forms.button wire:click="runScheduler" class="bg-gray-500 hover:bg-gray-600">
                                                Ejecutar ahora
                                            </x-forms.button>
                                        </div>
                                    </div>

                                    <div class="text-sm text-gray-600 dark:text-gray-400 mb-2">Salida:</div>
                                    <pre class="w-full whitespace-pre-wrap break-words bg-white dark:bg-coolgray-900 text-gray-900 dark:text-gray-100 border border-coolgray-300 dark:border-coolgray-600 px-4 py-3 rounded text-sm font-mono min-h-32">{{ $schedulerOutput }}</pre>
                                </div>
                            @endif
                        @endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

