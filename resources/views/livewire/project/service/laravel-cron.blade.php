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

        <div class="flex w-full flex-col overflow-x-hidden gap-6">
            <div class="rounded-lg border border-coolgray-300 bg-white px-5 py-4 text-black shadow-sm">
                <h2 class="text-xl font-bold !text-black" style="color: #000000;">Laravel Scheduler (Cron)</h2>
            </div>

            <div class="box-without-bg">
                <div class="w-full">
                @if (empty($laravelContainers))
                    <div class="rounded-lg border border-coolgray-200 bg-white p-4 text-sm text-black">
                        No Laravel containers detected in this service.
                    </div>
                @else
                    <div class="box-without-bg-without-border rounded-xl border border-coolgray-300 bg-white p-6 text-black shadow-sm">
                        <div class="rounded-lg border border-coolgray-300 bg-white p-4 text-black">
                            <div class="mb-3 mt-2 flex items-center justify-between">
                                <h3 class="font-semibold !text-black" style="color: #000000;">Tareas programadas</h3>
                                <x-forms.button
                                    wire:click="loadScheduleList"
                                    wire:loading.attr="disabled"
                                    wire:target="loadScheduleList"
                                    class="border border-black bg-white text-black hover:bg-white hover:text-black">
                                    Recargar
                                </x-forms.button>
                            </div>

                            @if ($isLoadingScheduleList)
                                <div class="rounded-md border border-coolgray-200 bg-white p-4 text-sm text-black">
                                    Cargando schedule list...
                                </div>
                            @elseif (empty($scheduledTasks))
                                <div class="rounded-md border border-coolgray-200 bg-white p-4 text-sm text-black">
                                    No hay tareas programadas detectadas.
                                </div>
                            @else
                                <div class="space-y-4 rounded-lg border border-coolgray-300 bg-white p-4">
                                    @foreach ($scheduledTasks as $taskIndex => $task)
                                        <div class="rounded-lg border border-coolgray-300 bg-white p-4 text-black">
                                            <div class="mb-4 grid gap-3 lg:grid-cols-[minmax(0,2.2fr)_minmax(0,1fr)_minmax(0,1fr)_auto]">
                                                <div class="min-w-0">
                                                    <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-black">Command</div>
                                                    <div class="font-mono text-sm whitespace-pre-wrap break-words text-black">{{ $task['command'] }}</div>
                                                </div>
                                                <div class="min-w-0">
                                                    <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-black">Intervalo</div>
                                                    <div class="font-mono text-sm whitespace-pre-wrap break-words text-black">{{ $task['expression'] }}</div>
                                                </div>
                                                <div class="min-w-0">
                                                    <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-black">Origen</div>
                                                    <div class="text-sm whitespace-pre-wrap break-words text-black">{{ $task['description'] }}</div>
                                                </div>
                                                <div class="flex items-start lg:justify-end">
                                                    <x-forms.button
                                                        wire:click="executeTaskNow({{ $taskIndex }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="executeTaskNow"
                                                        class="border border-black bg-white text-black hover:bg-white hover:text-black">
                                                        Ejecutar ahora
                                                    </x-forms.button>
                                                </div>
                                            </div>

                                            <div class="grid gap-3 sm:grid-cols-2">
                                                <div class="rounded-md border border-coolgray-200 bg-white p-3">
                                                    <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-black">Próxima</div>
                                                    <div class="text-sm whitespace-pre-wrap break-words text-black">{{ $task['next_due'] }}</div>
                                                </div>
                                                <div class="rounded-md border border-coolgray-200 bg-white p-3">
                                                    <div class="mb-1 text-xs font-semibold uppercase tracking-wide text-black">Última</div>
                                                    <div class="text-sm whitespace-pre-wrap break-words text-black">{{ $task['last_run'] }}</div>
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif

                            <div class="mt-6">
                                <div class="mb-2 text-sm font-medium text-black">Salida:</div>
                                <pre class="min-h-32 w-full whitespace-pre-wrap break-words rounded border border-coolgray-300 bg-white px-4 py-3 font-mono text-sm text-black">{{ $schedulerOutput }}</pre>
                            </div>
                        </div>
                    </div>
                @endif
                </div>
            </div>
        </div>
    </div>
</div>

