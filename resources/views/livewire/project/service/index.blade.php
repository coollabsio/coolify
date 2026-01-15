<div>
    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="$query" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        @if ($resourceType === 'database')
            <x-service-database.sidebar :parameters="$parameters" :serviceDatabase="$serviceDatabase" :isImportSupported="$isImportSupported" />
        @else
            <div class="sub-menu-wrapper">
                <a class="sub-menu-item"
                    class="{{ request()->routeIs('project.service.configuration') ? 'menu-item-active' : '' }}"
                    {{ wireNavigate() }}
                    href="{{ route('project.service.configuration', [...$parameters, 'stack_service_uuid' => null]) }}">
                    <svg xmlns="http://www.w3.org/2000/svg" class="sub-menu-item-icon" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                    </svg>
                    <span class="menu-item-label">Back</span>
                </a>
                <a class="sub-menu-item menu-item-active" href="#"><span class="menu-item-label">General</span></a>
            </div>
        @endif
        <div class="w-full">
            @if ($resourceType === 'application')
                <x-slot:title>
                    {{ data_get_str($service, 'name')->limit(10) }} >
                    {{ data_get_str($serviceApplication, 'name')->limit(10) }} | Coolify
                </x-slot>
                <form wire:submit='submitApplication'>
                    <div class="flex items-center gap-2 pb-4">
                        @if ($serviceApplication->human_name)
                            <h2>{{ Str::headline($serviceApplication->human_name) }}</h2>
                        @else
                            <h2>{{ Str::headline($serviceApplication->name) }}</h2>
                        @endif
                        <x-forms.button canGate="update" :canResource="$serviceApplication" type="submit">Save</x-forms.button>
                        @can('update', $serviceApplication)
                            <x-modal-confirmation wire:click="convertToDatabase" title="Convert to Database"
                                buttonTitle="Convert to Database" submitAction="convertToDatabase" :actions="['The selected resource will be converted to a service database.']"
                                confirmationText="{{ Str::headline($serviceApplication->name) }}"
                                confirmationLabel="Please confirm the execution of the actions by entering the Service Application Name below"
                                shortConfirmationLabel="Service Application Name" />
                        @endcan
                        @can('delete', $serviceApplication)
                            <x-modal-confirmation title="Confirm Service Application Deletion?" buttonTitle="Delete" isErrorButton
                                submitAction="deleteApplication" :actions="['The selected service application container will be stopped and permanently deleted.']"
                                confirmationText="{{ Str::headline($serviceApplication->name) }}"
                                confirmationLabel="Please confirm the execution of the actions by entering the Service Application Name below"
                                shortConfirmationLabel="Service Application Name" />
                        @endcan
                    </div>
                    <div class="flex flex-col gap-2">
                        @if ($requiredPort && !$serviceApplication->serviceType()?->contains(str($serviceApplication->image)->before(':')))
                            <x-callout type="warning" title="Required Port: {{ $requiredPort }}" class="mb-2">
                                This service requires port <strong>{{ $requiredPort }}</strong> to function correctly. All domains must include this port number (or any other port if you know what you're doing).
                                <br><br>
                                <strong>Example:</strong> http://app.coolify.io:{{ $requiredPort }}
                            </x-callout>
                        @endif

                        <div class="flex gap-2">
                            <x-forms.input canGate="update" :canResource="$serviceApplication" label="Name" id="humanName"
                                placeholder="Human readable name"></x-forms.input>
                            <x-forms.input canGate="update" :canResource="$serviceApplication" label="Description"
                                id="description"></x-forms.input>
                        </div>
                        <div class="flex gap-2">
                            @if (!$serviceApplication->serviceType()?->contains(str($serviceApplication->image)->before(':')))
                                @if ($serviceApplication->required_fqdn)
                                    <x-forms.input canGate="update" :canResource="$serviceApplication" required placeholder="https://app.coolify.io"
                                        label="Domains" id="fqdn"
                                        helper="You can specify one domain with path or more with comma. You can specify a port to bind the domain to.<br><br><span class='text-helper'>Example</span><br>- http://app.coolify.io,https://cloud.coolify.io/dashboard<br>- http://app.coolify.io/api/v3<br>- http://app.coolify.io:3000 -> app.coolify.io will point to port 3000 inside the container. "></x-forms.input>
                                @else
                                    <x-forms.input canGate="update" :canResource="$serviceApplication" placeholder="https://app.coolify.io"
                                        label="Domains" id="fqdn"
                                        helper="You can specify one domain with path or more with comma. You can specify a port to bind the domain to.<br><br><span class='text-helper'>Example</span><br>- http://app.coolify.io,https://cloud.coolify.io/dashboard<br>- http://app.coolify.io/api/v3<br>- http://app.coolify.io:3000 -> app.coolify.io will point to port 3000 inside the container. "></x-forms.input>
                                @endif
                            @endif
                            <x-forms.input canGate="update" :canResource="$serviceApplication"
                                helper="You can change the image you would like to deploy.<br><br><span class='dark:text-warning'>WARNING. You could corrupt your data. Only do it if you know what you are doing.</span>"
                                label="Image" id="image"></x-forms.input>
                        </div>
                    </div>
                    <h3 class="py-2 pt-4">Advanced</h3>
                    <div class="w-96 flex flex-col gap-1">
                        @if (str($serviceApplication->image)->contains('pocketbase'))
                            <x-forms.checkbox canGate="update" :canResource="$serviceApplication" instantSave="instantSaveApplicationSettings" id="isGzipEnabled"
                                label="Enable Gzip Compression"
                                helper="Pocketbase does not need gzip compression, otherwise SSE will not work." disabled />
                        @else
                            <x-forms.checkbox canGate="update" :canResource="$serviceApplication" instantSave="instantSaveApplicationSettings" id="isGzipEnabled"
                                label="Enable Gzip Compression"
                                helper="You can disable gzip compression if you want. Some services are compressing data by default. In this case, you do not need this." />
                        @endif
                        <x-forms.checkbox canGate="update" :canResource="$serviceApplication" instantSave="instantSaveApplicationSettings" id="isStripprefixEnabled"
                            label="Strip Prefixes"
                            helper="Strip Prefix is used to remove prefixes from paths. Like /api/ to /api." />
                        <x-forms.checkbox canGate="update" :canResource="$serviceApplication" instantSave="instantSaveApplicationSettings" label="Exclude from service status"
                            helper="If you do not need to monitor this resource, enable. Useful if this service is optional."
                            id="excludeFromStatus"></x-forms.checkbox>
                        <x-forms.checkbox canGate="update" :canResource="$serviceApplication"
                            helper="Drain logs to your configured log drain endpoint in your Server settings."
                            instantSave="instantSaveApplicationAdvanced" id="isLogDrainEnabled" label="Drain Logs" />
                    </div>
                </form>

                <x-domain-conflict-modal
                    :conflicts="$domainConflicts"
                    :showModal="$showDomainConflictModal"
                    confirmAction="confirmDomainUsage">
                    <x-slot:consequences>
                        <ul class="mt-2 ml-4 list-disc">
                            <li>Only one service will be accessible at this domain</li>
                            <li>The routing behavior will be unpredictable</li>
                            <li>You may experience service disruptions</li>
                            <li>SSL certificates might not work correctly</li>
                        </ul>
                    </x-slot:consequences>
                </x-domain-conflict-modal>

                @if ($showPortWarningModal)
                    <div x-data="{ modalOpen: true }" x-init="$nextTick(() => { modalOpen = true })"
                        @keydown.escape.window="modalOpen = false; $wire.call('cancelRemovePort')"
                        :class="{ 'z-40': modalOpen }" class="relative w-auto h-auto">
                        <template x-teleport="body">
                            <div x-show="modalOpen"
                                class="fixed top-0 lg:pt-10 left-0 z-99 flex items-start justify-center w-screen h-screen" x-cloak>
                                <div x-show="modalOpen" class="absolute inset-0 w-full h-full bg-black/20 backdrop-blur-xs"></div>
                                <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" x-transition:enter="ease-out duration-100"
                                    x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                                    x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                                    x-transition:leave="ease-in duration-100"
                                    x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                                    x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                                    class="relative w-full py-6 border rounded-sm min-w-full lg:min-w-[36rem] max-w-[48rem] bg-neutral-100 border-neutral-400 dark:bg-base px-7 dark:border-coolgray-300">
                                    <div class="flex justify-between items-center pb-3">
                                        <h2 class="pr-8 font-bold">Remove Required Port?</h2>
                                        <button @click="modalOpen = false; $wire.call('cancelRemovePort')"
                                            class="flex absolute top-2 right-2 justify-center items-center w-8 h-8 rounded-full dark:text-white hover:bg-coolgray-300">
                                            <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                                stroke-width="1.5" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </button>
                                    </div>
                                    <div class="relative w-auto">
                                        <x-callout type="warning" title="Port Requirement Warning" class="mb-4">
                                            This service requires port <strong>{{ $requiredPort }}</strong> to function correctly.
                                            One or more of your domains are missing a port number.
                                        </x-callout>

                                        <x-callout type="danger" title="What will happen if you continue?" class="mb-4">
                                            <ul class="mt-2 ml-4 list-disc">
                                                <li>The service may become unreachable</li>
                                                <li>The proxy may not be able to route traffic correctly</li>
                                                <li>Environment variables may not be generated properly</li>
                                                <li>The service may fail to start or function</li>
                                            </ul>
                                        </x-callout>

                                        <div class="flex flex-wrap gap-2 justify-between mt-4">
                                            <x-forms.button @click="modalOpen = false; $wire.call('cancelRemovePort')"
                                                class="w-auto dark:bg-coolgray-200 dark:hover:bg-coolgray-300">
                                                Cancel - Keep Port
                                            </x-forms.button>
                                            <x-forms.button wire:click="confirmRemovePort" @click="modalOpen = false" class="w-auto"
                                                isError>
                                                I understand, remove port anyway
                                            </x-forms.button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                @endif
            @elseif ($resourceType === 'database')
                <x-slot:title>
                    {{ data_get_str($service, 'name')->limit(10) }} >
                    {{ data_get_str($serviceDatabase, 'name')->limit(10) }} | Coolify
                </x-slot>
                @if ($currentRoute === 'project.service.database.import')
                    <livewire:project.database.import :resource="$serviceDatabase" :key="'import-' . $serviceDatabase->uuid" />
                @else
                    <form wire:submit='submitDatabase'>
                        <div class="flex items-center gap-2 pb-4">
                            @if ($serviceDatabase->human_name)
                                <h2>{{ Str::headline($serviceDatabase->human_name) }}</h2>
                            @else
                                <h2>{{ Str::headline($serviceDatabase->name) }}</h2>
                            @endif
                            <x-forms.button canGate="update" :canResource="$serviceDatabase" type="submit">Save</x-forms.button>
                            @can('update', $serviceDatabase)
                                <x-modal-confirmation wire:click="convertToApplication" title="Convert to Application"
                                    buttonTitle="Convert to Application" submitAction="convertToApplication" :actions="['The selected resource will be converted to an application.']"
                                    confirmationText="{{ Str::headline($serviceDatabase->name) }}"
                                    confirmationLabel="Please confirm the execution of the actions by entering the Service Database Name below"
                                    shortConfirmationLabel="Service Database Name" />
                            @endcan
                            @can('delete', $serviceDatabase)
                                <x-modal-confirmation title="Confirm Service Database Deletion?" buttonTitle="Delete"
                                    isErrorButton submitAction="deleteDatabase" :actions="[
                                        'The selected service database container will be stopped and permanently deleted.',
                                    ]"
                                    confirmationText="{{ Str::headline($serviceDatabase->name) }}"
                                    confirmationLabel="Please confirm the execution of the actions by entering the Service Database Name below"
                                    shortConfirmationLabel="Service Database Name" />
                            @endcan
                        </div>
                        <div class="flex flex-col gap-2">
                            <div class="flex gap-2">
                                <x-forms.input canGate="update" :canResource="$serviceDatabase" label="Name" id="humanName"
                                    placeholder="Name"></x-forms.input>
                                <x-forms.input canGate="update" :canResource="$serviceDatabase" label="Description"
                                    id="description"></x-forms.input>
                                <x-forms.input canGate="update" :canResource="$serviceDatabase" required
                                    helper="You can change the image you would like to deploy.<br><br><span class='dark:text-warning'>WARNING. You could corrupt your data. Only do it if you know what you are doing.</span>"
                                    label="Image" id="image"></x-forms.input>
                            </div>
                            <div class="flex flex-col gap-2">
                                <div class="flex items-center gap-2 py-2">
                                    <h3>Proxy</h3>
                                    <x-loading wire:loading wire:target="instantSave" />
                                    @if ($serviceDatabase->is_public)
                                        <x-slide-over fullScreen>
                                            <x-slot:title>Proxy Logs</x-slot:title>
                                            <x-slot:content>
                                                <livewire:project.shared.get-logs :server="$server" :resource="$service"
                                                    :servicesubtype="$serviceDatabase" container="{{ $serviceDatabase->uuid }}-proxy" :collapsible="false" lazy />
                                            </x-slot:content>
                                            <x-forms.button @click="slideOverOpen=true">Logs</x-forms.button>
                                        </x-slide-over>
                                    @endif
                                </div>
                                <div class="flex flex-col gap-2 w-64">
                                    <x-forms.checkbox canGate="update" :canResource="$serviceDatabase" instantSave id="isPublic"
                                        label="Make it publicly available" />
                                </div>
                                <x-forms.input canGate="update" :canResource="$serviceDatabase" placeholder="5432"
                                    disabled="{{ $serviceDatabase->is_public }}" id="publicPort" label="Public Port" />
                                @if ($db_url_public)
                                    <x-forms.input label="Database IP:PORT (public)"
                                        helper="Your credentials are available in your environment variables." type="password"
                                        readonly wire:model="db_url_public" />
                                @endif
                            </div>
                        </div>
                        <h3 class="pt-2">Advanced</h3>
                        <div class="w-96">
                            <x-forms.checkbox canGate="update" :canResource="$serviceDatabase" instantSave="instantSaveExclude"
                                label="Exclude from service status"
                                helper="If you do not need to monitor this resource, enable. Useful if this service is optional."
                                id="excludeFromStatus"></x-forms.checkbox>
                            <x-forms.checkbox canGate="update" :canResource="$serviceDatabase"
                                helper="Drain logs to your configured log drain endpoint in your Server settings."
                                instantSave="instantSaveLogDrain" id="isLogDrainEnabled" label="Drain Logs" />
                        </div>
                    </form>
                @endif
            @endif
        </div>
    </div>
</div>
