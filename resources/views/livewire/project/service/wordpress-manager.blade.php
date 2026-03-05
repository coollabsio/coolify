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
        <div class="w-full">
            <div class="box-without-bg">
                <h2 class="text-xl font-bold dark:text-white mb-4">WordPress Manager</h2>
                
                @if (empty($wordpressContainers))
                    <div class="p-4 text-sm text-neutral-500">
                        No WordPress containers detected in this service.
                    </div>
                @else
                    <div class="mb-6">
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
                @endif
            </div>
        </div>
    </div>
</div>
