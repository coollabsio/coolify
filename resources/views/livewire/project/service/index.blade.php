<div x-data="{ raw: true, activeTab: window.location.hash ? window.location.hash.substring(1) : 'service-stack' }">
    <livewire:project.service.navbar :service="$service" :parameters="$parameters" :query="$query" />
    <div class="flex h-full pt-6">
        <div class="flex flex-col gap-4 min-w-fit">
            <a target="_blank" href="{{ $service->documentation() }}">Documentation <x-external-link /></a>
            <a :class="activeTab === 'service-stack' && 'text-white'"
                @click.prevent="activeTab = 'service-stack'; window.location.hash = 'service-stack'"
                href="#">Service Stack</a>
            <a :class="activeTab === 'compose' && 'text-white'"
                @click.prevent="activeTab = 'compose'; window.location.hash = 'compose'" href="#">Compose File</a>
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
                <form wire:submit.prevent='submit' class="pb-4">
                    <div class="flex gap-2">
                        <h2 class="pb-4">Configuration </h2>
                        <x-forms.button type="submit">Save</x-forms.button>

                    </div>
                    <div class="flex gap-2">
                        <x-forms.input id="service.name" required label="Service Name"
                            placeholder="My super wordpress site" />
                        <x-forms.input id="service.description" label="Description" />
                    </div>
                </form>
                <div class="flex gap-2">
                    <h2 class="pb-4"> Service Stack </h2>
                    <x-forms.button wire:click='manualRefreshStack'>Refresh</x-forms.button>
                </div>
                <div class="grid grid-cols-1 gap-2 xl:grid-cols-3 ">
                    @foreach ($applications as $application)
                        <a @class([
                            'border-l border-dashed border-red-500' => Str::of(
                                $application->status)->contains(['exited']),
                            'border-l border-dashed border-success' => Str::of(
                                $application->status)->contains(['running']),
                            'border-l border-dashed border-warning' => Str::of(
                                $application->status)->contains(['starting']),
                            'flex flex-col justify-center box',
                        ])
                            href="{{ route('project.service.show', [...$parameters, 'service_name' => $application->name]) }}">
                            @if ($application->human_name)
                                {{ Str::headline($application->human_name) }}
                            @else
                                {{ Str::headline($application->name) }}
                            @endif
                            @if ($application->hasMissingFiles)
                                <span class="text-xs text-error">(has missing files)</span>
                            @endif
                            @if ($application->description)
                                <span class="text-xs">{{ $application->description }}</span>
                            @endif
                            @if ($application->fqdn)
                                <span class="text-xs">{{ $application->fqdn }}</span>
                            @endif
                            <div class="text-xs">{{ $application->status }}</div>
                        </a>
                    @endforeach
                    @foreach ($databases as $database)
                        <a @class([
                            'border-l border-dashed border-red-500' => Str::of(
                                $database->status)->contains(['exited']),
                            'border-l border-dashed border-success' => Str::of(
                                $database->status)->contains(['running']),
                            'border-l border-dashed border-warning' => Str::of(
                                $database->status)->contains(['restarting']),
                            'flex flex-col justify-center box',
                        ])
                            href="{{ route('project.service.show', [...$parameters, 'service_name' => $database->name]) }}">
                            @if ($database->human_name)
                                {{ Str::headline($database->human_name) }}
                            @else
                                {{ Str::headline($database->name) }}
                            @endif
                            @if ($database->hasMissingFiles)
                                <span class="text-xs text-error">(has missing files)</span>
                            @endif
                            @if ($database->description)
                                <span class="text-xs">{{ $database->description }}</span>
                            @endif
                            <div class="text-xs">{{ $database->status }}</div>
                        </a>
                    @endforeach
                </div>
            </div>
            <div x-cloak x-show="activeTab === 'compose'">
                <div x-cloak x-show="activeTab === 'compose'">
                    <div class="flex gap-2 pb-4">
                        <h2>Docker Compose</h2>
                        <div x-cloak x-show="raw">
                            <x-forms.button class="w-64" @click.prevent="raw = !raw">Show Deployable</x-forms.button>
                            <x-forms.button wire:click='save'>Save</x-forms.button>
                        </div>
                        <div x-cloak x-show="raw === false">
                            <x-forms.button class="w-64" @click.prevent="raw = !raw">Show Source</x-forms.button>
                            <x-forms.button disabled wire:click='save'>Save</x-forms.button>

                        </div>
                    </div>

                    <div x-cloak x-show="raw">
                        <x-forms.textarea label="Docker Compose file"
                            helper="
                        You can use these variables in your Docker Compose file and Coolify will generate default values or replace them with the values you set on the UI forms.<br>
                        <br>
                        - SERVICE_FQDN_*: FQDN - could be changable from the UI. (example: SERVICE_FQDN_GHOST)<br>
                        - SERVICE_URL_*: URL parsed from FQDN - could be changable from the UI. (example: SERVICE_URL_GHOST)<br>
                        - SERVICE_BASE64_64_*: Generated 'base64' string with length of '64' (example: SERVICE_BASE64_64_GHOST, to generate 32 bit: SERVICE_BASE64_32_GHOST)<br>
                        - SERVICE_USER_*: Generated user (example: SERVICE_USER_MYSQL)<br>
                        - SERVICE_PASSWORD_*: Generated password (example: SERVICE_PASSWORD_MYSQL)<br>"
                            rows="20" id="service.docker_compose_raw">
                        </x-forms.textarea>
                    </div>
                    <div x-cloak x-show="raw === false">
                        <x-forms.textarea label="Actual Docker Compose file that will be deployed" readonly
                            rows="20" id="service.docker_compose">
                        </x-forms.textarea>
                    </div>
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
