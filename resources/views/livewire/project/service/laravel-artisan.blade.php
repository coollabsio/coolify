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
                    <div class="box-without-bg-without-border dark:bg-coolgray-100 bg-white p-6">
                        <div class="mb-6">
                            <label class="block text-sm font-medium dark:text-white mb-2">Seleccionar Contenedor:</label>
                            <select
                                wire:model.live="selectedContainer"
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

                        <div class="mb-4">
                            <label class="block text-sm font-medium dark:text-white mb-2">Comando:</label>
                            <x-forms.input
                                id="command"
                                label=""
                                placeholder="Ej: migrate --force"
                            />
                            <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                Se ejecuta como: <span class="font-mono">php /var/www/html/artisan &lt;tu comando&gt;</span>
                            </div>
                        </div>

                        <div class="flex gap-2">
                            <x-forms.button wire:click="run" wire:loading.attr="disabled" wire:target="run" class="bg-coollabs">
                                Ejecutar
                            </x-forms.button>
                            <div wire:loading wire:target="run" class="text-sm text-gray-600 dark:text-gray-400 self-center">
                                Ejecutando...
                            </div>
                        </div>

                        <div class="mt-6">
                            <label class="block text-sm font-medium dark:text-white mb-2">Salida:</label>
                            <pre class="w-full whitespace-pre-wrap break-words bg-white dark:bg-coolgray-900 text-gray-900 dark:text-gray-100 border border-coolgray-300 dark:border-coolgray-600 px-4 py-3 rounded text-sm font-mono min-h-40">{{ $output }}</pre>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

