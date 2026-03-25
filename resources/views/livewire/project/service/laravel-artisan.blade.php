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
                        <div class="mb-4">
                            <label class="block text-sm font-medium dark:text-white mb-2">Seleccionar Contenedor:</label>
                            <select wire:model.live="selectedContainer" wire:change="loadArtisanCommands" class="input w-full">
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

                        <div class="flex flex-col gap-3 lg:flex-row lg:items-start">
                            <div class="flex-1">
                                <label class="block text-sm font-medium dark:text-white mb-2">Comando:</label>
                                <input
                                    type="text"
                                    wire:model.live="selectedCommand"
                                    list="artisan-command-list"
                                    class="input w-full"
                                    placeholder="Ej: migrate --force"
                                />

                                <datalist id="artisan-command-list">
                                    @foreach ($artisanCommands as $cmd)
                                        <option value="{{ $cmd['name'] }}"></option>
                                    @endforeach
                                </datalist>

                                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    Se ejecuta como:
                                    <span class="font-mono">{{ 'php /var/www/html/artisan '.$selectedCommand }}</span>
                                </div>
                            </div>

                            <div class="w-full lg:w-80">
                                <label class="block text-sm font-medium dark:text-white mb-2">Descripción:</label>
                                <div class="p-3 border border-coolgray-300 dark:border-coolgray-600 rounded bg-white dark:bg-coolgray-800 text-sm text-gray-700 dark:text-gray-200 min-h-12">
                                    {{ $selectedCommandDescription ?: '—' }}
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 flex gap-2">
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

