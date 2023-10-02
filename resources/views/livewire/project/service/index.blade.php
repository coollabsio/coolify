<div x-data="{ raw: true, activeTab: window.location.hash ? window.location.hash.substring(1) : 'service-stack' }" wire:poll.10000ms="checkStatus">
    <livewire:project.service.navbar :service="$service" :parameters="$parameters" :query="$query" />
    <livewire:project.service.compose-modal :raw="$service->docker_compose_raw" :actual="$service->docker_compose" />
    <div class="flex h-full pt-6">
        <div class="flex flex-col items-start gap-4 min-w-fit">
            <a target="_blank" href="{{ $service->documentation() }}">Documentation <x-external-link /></a>
            <a :class="activeTab === 'service-stack' && 'text-white'"
                @click.prevent="activeTab = 'service-stack'; window.location.hash = 'service-stack'"
                href="#">Service Stack</a>
            <a :class="activeTab === 'environment-variables' && 'text-white'"
                @click.prevent="activeTab = 'environment-variables'; window.location.hash = 'environment-variables'"
                href="#">Environment
                Variables</a>
            <a :class="activeTab === 'danger' && 'text-white'"
                @click.prevent="activeTab = 'danger'; window.location.hash = 'danger'" href="#">Danger Zone
            </a>
        </div>
        <div class="w-full pl-8">
            <div x-cloak x-show="activeTab === 'service-stack'">
                <form wire:submit.prevent='submit' class="flex flex-col gap-4 pb-2">
                    <div class="flex gap-2">
                        <div>
                            <h2> Service Stack </h2>
                            <div>Configuration</div>
                        </div>
                        <x-forms.button type="submit">Save</x-forms.button>
                        <x-forms.button class="w-64" onclick="composeModal.showModal()">Edit Compose
                            File</x-forms.button>
                    </div>
                    <div class="flex gap-2">
                        <x-forms.input id="service.name" required label="Service Name"
                            placeholder="My super wordpress site" />
                        <x-forms.input id="service.description" label="Description" />
                    </div>
                </form>
                <div class="grid grid-cols-1 gap-2 pt-4 xl:grid-cols-3">
                    @foreach ($service->applications as $application)
                        <div @class([
                            'border-l border-dashed border-red-500' => Str::of(
                                $application->status)->contains(['exited']),
                            'border-l border-dashed border-success' => Str::of(
                                $application->status)->contains(['running']),
                            'border-l border-dashed border-warning' => Str::of(
                                $application->status)->contains(['starting']),
                            'flex gap-2 box group',
                        ])>
                            <a class="flex flex-col flex-1 group-hover:text-white hover:no-underline"
                                href="{{ route('project.service.show', [...$parameters, 'service_name' => $application->name]) }}">

                                @if ($application->human_name)
                                    {{ Str::headline($application->human_name) }}
                                @else
                                    {{ Str::headline($application->name) }}
                                @endif
                                @if ($application->configuration_required)
                                    <span class="text-xs text-error">(configuration required)</span>
                                @endif
                                @if ($application->description)
                                    <span class="text-xs">{{ Str::limit($application->description, 60) }}</span>
                                @endif
                                @if ($application->fqdn)
                                    <span class="text-xs">{{ Str::limit($application->fqdn, 60) }}</span>
                                @endif
                                <div class="text-xs">{{ $application->status }}</div>
                            </a>
                            <a class="flex gap-2 p-1 mx-4 text-xs font-bold rounded hover:no-underline hover:text-warning"
                                href="{{ route('project.service.logs', [...$parameters, 'service_name' => $application->name]) }}">Logs</a>
                        </div>
                    @endforeach
                    @foreach ($databases as $database)
                        <div @class([
                            'border-l border-dashed border-red-500' => Str::of(
                                $database->status)->contains(['exited']),
                            'border-l border-dashed border-success' => Str::of(
                                $database->status)->contains(['running']),
                            'border-l border-dashed border-warning' => Str::of(
                                $database->status)->contains(['restarting']),
                            'flex gap-2 box group',
                        ])>
                            <a class="flex flex-col flex-1 group-hover:text-white hover:no-underline"
                                href="{{ route('project.service.show', [...$parameters, 'service_name' => $database->name]) }}">
                                @if ($database->human_name)
                                    {{ Str::headline($database->human_name) }}
                                @else
                                    {{ Str::headline($database->name) }}
                                @endif
                                @if ($database->configuration_required)
                                    <span class="text-xs text-error">(configuration required)</span>
                                @endif
                                @if ($database->description)
                                    <span class="text-xs">{{ Str::limit($database->description, 60) }}</span>
                                @endif
                                <div class="text-xs">{{ $database->status }}</div>
                            </a>
                            <a class="flex gap-2 p-1 mx-4 text-xs font-bold rounded hover:no-underline hover:text-warning"
                                href="{{ route('project.service.logs', [...$parameters, 'service_name' => $database->name]) }}">Logs</a>
                        </div>
                    @endforeach
                </div>

            </div>
            <div x-cloak x-show="activeTab === 'environment-variables'">
                <div x-cloak x-show="activeTab === 'environment-variables'">
                    <livewire:project.shared.environment-variable.all :resource="$service" />
                </div>
            </div>
            <div x-cloak x-show="activeTab === 'danger'">
                <livewire:project.shared.danger :resource="$service" />
            </div>
        </div>
    </div>
