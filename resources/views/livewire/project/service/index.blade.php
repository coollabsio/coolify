<div x-data="{ raw: true, activeTab: window.location.hash ? window.location.hash.substring(1) : 'service-stack' }">
    <livewire:project.service.navbar :service="$service" :parameters="$parameters" :query="$query" />
    <div class="flex h-full pt-6">
        <div class="flex flex-col gap-4 min-w-fit">
            <a :class="activeTab === 'service-stack' && 'text-white'"
                @click.prevent="activeTab = 'service-stack'; window.location.hash = 'service-stack'" href="#">Service Stack</a>
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
                <h2 class="pb-4"> Service Stack </h2>
                <div class="grid grid-cols-1 gap-2">
                    @foreach ($service->applications as $application)
                        <a class="flex flex-col justify-center box"
                            href="{{ route('project.service.show', [...$parameters, 'service_name' => $application->name]) }}">
                            @if ($application->human_name)
                                {{ Str::headline($application->human_name) }}
                            @else
                                {{ Str::headline($application->name) }}
                            @endif
                            @if ($application->fqdn)
                                <span class="text-xs">{{ $application->fqdn }}</span>
                            @endif
                        </a>
                    @endforeach
                    @foreach ($service->databases as $database)
                        <a class="justify-center box"
                            href="{{ route('project.service.show', [...$parameters, 'service_name' => $database->name]) }}">
                            @if ($database->human_name)
                                {{ Str::headline($database->human_name) }}
                            @else
                                {{ Str::headline($database->name) }}
                            @endif
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
                        <x-forms.textarea rows="20" id="service.docker_compose_raw">
                        </x-forms.textarea>
                    </div>
                    <div x-cloak x-show="raw === false">
                        <x-forms.textarea readonly rows="20" id="service.docker_compose">
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
