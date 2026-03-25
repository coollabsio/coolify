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
                        <div class="p-4 border border-coolgray-300 dark:border-coolgray-600 rounded bg-white dark:bg-coolgray-800">
                            <div class="mt-2 flex items-center justify-between mb-3">
                                <h3 class="font-semibold dark:text-white">Tareas programadas</h3>
                                <x-forms.button
                                    wire:click="loadScheduleList"
                                    wire:loading.attr="disabled"
                                    wire:target="loadScheduleList"
                                    class="bg-gray-500 hover:bg-gray-600">
                                    Recargar
                                </x-forms.button>
                            </div>

                            @if ($isLoadingScheduleList)
                                <div class="p-4 text-sm text-gray-600 dark:text-gray-300">
                                    Cargando schedule list...
                                </div>
                            @elseif (empty($scheduledTasks))
                                <div class="p-4 text-sm text-gray-600 dark:text-gray-300">
                                    No hay tareas programadas detectadas.
                                </div>
                            @else
                                <div class="overflow-x-auto">
                                    <table class="w-full text-sm">
                                        <thead>
                                        <tr class="text-left text-xs text-gray-500 dark:text-gray-400">
                                            <th class="px-2 py-2">Command</th>
                                            <th class="px-2 py-2">Intervalo</th>
                                            <th class="px-2 py-2">Próxima</th>
                                            <th class="px-2 py-2">Última</th>
                                            <th class="px-2 py-2">Acción</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach ($scheduledTasks as $taskIndex => $task)
                                            <tr class="border-t border-coolgray-200 dark:border-coolgray-700">
                                                <td class="px-2 py-2">
                                                    <span class="font-mono">{{ $task['command'] }}</span>
                                                </td>
                                                <td class="px-2 py-2">
                                                    <span class="font-mono">{{ $task['expression'] }}</span>
                                                </td>
                                                <td class="px-2 py-2">
                                                    {{ $task['next_due'] }}
                                                </td>
                                                <td class="px-2 py-2">
                                                    {{ $task['last_run'] }}
                                                </td>
                                                <td class="px-2 py-2">
                                                    <x-forms.button
                                                        wire:click="executeTaskNow({{ $taskIndex }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="executeTaskNow"
                                                        class="bg-coollabs">
                                                        Ejecutar ahora
                                                    </x-forms.button>
                                                </td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif

                            <div class="mt-6">
                                <div class="text-sm text-gray-600 dark:text-gray-400 mb-2">Salida:</div>
                                <pre class="w-full whitespace-pre-wrap break-words bg-white dark:bg-coolgray-900 text-gray-900 dark:text-gray-100 border border-coolgray-300 dark:border-coolgray-600 px-4 py-3 rounded text-sm font-mono min-h-32">{{ $schedulerOutput }}</pre>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

