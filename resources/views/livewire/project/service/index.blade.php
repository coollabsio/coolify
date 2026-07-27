<div>
    <livewire:project.service.heading :service="$service" :parameters="$parameters" :query="$query" />
    <section class="application-settings-workspace mt-8 w-full max-w-[1180px] xl:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-10">
        @if ($resourceType === 'database')
            <x-service-database.sidebar :parameters="$parameters" :serviceDatabase="$serviceDatabase" :isImportSupported="$isImportSupported" />
        @else
            <aside class="application-settings-navigation min-w-0 xl:sticky xl:top-26 xl:self-start">
                <nav aria-label="Compose resource settings"
                    class="grid grid-cols-2 gap-0.5 border-y border-neutral-200 py-4 sm:grid-cols-3 xl:grid-cols-1 xl:border-y-0 xl:py-0 dark:border-white/[0.06]">
                    <div class="nav-section hidden xl:block">Compose resource</div>
                <a class="menu-item" {{ wireNavigate() }}
                    href="{{ route('project.service.configuration', [...$parameters, 'stack_service_uuid' => null]) }}">
                    <x-reicon name="logout" class="menu-item-icon rotate-180" />
                    <span class="menu-item-label">Back to service</span>
                </a>
                <a @class(['menu-item', 'menu-item-active' => request()->routeIs('project.service.index')])
                    {{ wireNavigate() }} href="{{ route('project.service.index', $parameters) }}">
                    <x-reicon name="settings" class="menu-item-icon" />
                    <span class="menu-item-label">General</span>
                </a>
                <a @class(['menu-item', 'menu-item-active' => request()->routeIs('project.service.index.advanced')])
                    {{ wireNavigate() }} href="{{ route('project.service.index.advanced', $parameters) }}">
                    <x-reicon name="grid" class="menu-item-icon" />
                    <span class="menu-item-label">Advanced</span>
                </a>
                </nav>
            </aside>
        @endif
        <div class="min-w-0 xl:mt-3">
            @if ($resourceType === 'application')
                <x-slot:title>
                    {{ data_get_str($service, 'name')->limit(10) }} >
                    {{ data_get_str($serviceApplication, 'name')->limit(10) }} | Coolify
                </x-slot>
                @if ($currentRoute === 'project.service.index.advanced')
                    <section class="application-settings-section">
                        <div class="application-settings-section-header">
                            <div>
                                <h2>Advanced</h2>
                                <p>Control proxy, status, and logging behavior for this compose resource.</p>
                            </div>
                        </div>
                        <div class="application-settings-section-body grid gap-4 sm:grid-cols-2">
                        @if (str($serviceApplication->image)->contains('pocketbase'))
                            <x-forms.listbox id="isGzipEnabled" label="Gzip compression"
                                helper="PocketBase keeps compression disabled so server-sent events continue to work."
                                :disabled="true" :options="[
                                    ['value' => true, 'label' => 'Enabled'],
                                    ['value' => false, 'label' => 'Disabled'],
                                ]" />
                        @else
                            <x-forms.listbox id="isGzipEnabled" label="Gzip compression"
                                onChange="instantSaveApplicationSettings" :options="[
                                    ['value' => true, 'label' => 'Enabled'],
                                    ['value' => false, 'label' => 'Disabled'],
                                ]" />
                        @endif
                        <x-forms.listbox id="isStripprefixEnabled" label="Path prefixes"
                            onChange="instantSaveApplicationSettings" :options="[
                                ['value' => true, 'label' => 'Strip prefixes'],
                                ['value' => false, 'label' => 'Keep prefixes'],
                            ]" />
                        <x-forms.listbox id="excludeFromStatus" label="Service status"
                            onChange="instantSaveApplicationSettings" :options="[
                                ['value' => false, 'label' => 'Include in status'],
                                ['value' => true, 'label' => 'Exclude from status'],
                            ]" />
                        <x-forms.listbox id="isLogDrainEnabled" label="Log drain"
                            onChange="instantSaveApplicationAdvanced" :options="[
                                ['value' => true, 'label' => 'Send logs to drain'],
                                ['value' => false, 'label' => 'Do not drain logs'],
                            ]" />
                        </div>
                    </section>
                @else
                    <form wire:submit="submitApplication" class="space-y-6">
                        <x-unsaved-bar action="submitApplication" />
                        <section class="application-settings-section">
                            <div class="application-settings-section-header">
                                <div>
                                    <h2>{{ Str::headline($serviceApplication->human_name ?: $serviceApplication->name) }}</h2>
                                    <p>Identity, image, and public access for this compose application.</p>
                                </div>
                                <div class="flex items-center gap-2">
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
                            </div>
                            <div class="application-settings-section-body space-y-4">
                            @if ($requiredPort && !$serviceApplication->serviceType()?->contains(str($serviceApplication->image)->before(':')))
                                <x-callout type="info" title="Required Port: {{ $requiredPort }}" class="mb-2">
                                    This service requires port <strong>{{ $requiredPort }}</strong> to function correctly. All domains must include this port number (or any other port if you know what you're doing).
                                    <br><br>
                                    <strong>Example:</strong> https://app.coolify.io:{{ $requiredPort }},https://www.app.coolify.io:{{ $requiredPort }}
                                </x-callout>
                            @endif

                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-forms.input canGate="update" :canResource="$serviceApplication" label="Name" id="humanName"
                                    placeholder="Human readable name"></x-forms.input>
                                <x-forms.input canGate="update" :canResource="$serviceApplication" label="Description"
                                    id="description"></x-forms.input>
                            </div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                @if (!$serviceApplication->serviceType()?->contains(str($serviceApplication->image)->before(':')))
                                    @if ($serviceApplication->required_fqdn)
                                        <x-forms.input canGate="update" :canResource="$serviceApplication" required placeholder="https://app.coolify.io"
                                            label="Domains" id="fqdn"
                                            helper="You can specify one domain with path or more with comma. You can specify a port to bind the domain to.<br><br><span class='text-helper'>Example</span><br>- https://app.coolify.io,https://cloud.coolify.io/dashboard<br>- https://app.coolify.io/api/v3<br>- https://app.coolify.io:3000 -> app.coolify.io will point to port 3000 inside the container.<br>- https://app.coolify.io:8080/api -> app.coolify.io/api will point to port 8080 inside the container."></x-forms.input>
                                    @else
                                        <x-forms.input canGate="update" :canResource="$serviceApplication" placeholder="https://app.coolify.io"
                                            label="Domains" id="fqdn"
                                            helper="You can specify one domain with path or more with comma. You can specify a port to bind the domain to.<br><br><span class='text-helper'>Example</span><br>- https://app.coolify.io,https://cloud.coolify.io/dashboard<br>- https://app.coolify.io/api/v3<br>- https://app.coolify.io:3000 -> app.coolify.io will point to port 3000 inside the container.<br>- https://app.coolify.io:8080/api -> app.coolify.io/api will point to port 8080 inside the container."></x-forms.input>
                                    @endif
                                @endif
                                <x-forms.input canGate="update" :canResource="$serviceApplication"
                                    helper="You can change the image you would like to deploy.<br><br><span class='dark:text-warning'>WARNING. You could corrupt your data. Only do it if you know what you are doing.</span>"
                                    label="Image" id="image"></x-forms.input>
                            </div>
                            </div>
                        </section>
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
                            :class="{ 'z-40': modalOpen }" class="relative">
                            <template x-teleport="body">
                                <div x-show="modalOpen"
                                    class="fixed inset-0 z-99 flex min-h-full items-center justify-center overflow-y-auto p-4" x-cloak>
                                    <div x-show="modalOpen" class="absolute inset-0 bg-black/50 backdrop-blur-[2px]"></div>
                                    <div x-show="modalOpen" x-trap.inert.noscroll="modalOpen" x-transition:enter="ease-out duration-100"
                                        x-transition:enter-start="opacity-0 -translate-y-2 sm:scale-95"
                                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                                        x-transition:leave="ease-in duration-100"
                                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                                        x-transition:leave-end="opacity-0 -translate-y-2 sm:scale-95"
                                        class="application-settings-form application-settings-section relative w-full lg:min-w-[36rem] lg:max-w-2xl"
                                        style="box-shadow: 0 0 0 1px var(--coollabs-hairline), var(--shadow-modal)">
                                        <header>
                                            <h3>Remove required port?</h3>
                                            <button @click="modalOpen = false; $wire.call('cancelRemovePort')"
                                                class="flex size-7 items-center justify-center rounded-md text-neutral-500 transition-colors hover:bg-neutral-100 hover:text-black dark:text-fg-faint dark:hover:bg-white/[0.06] dark:hover:text-fg">
                                                <x-reicon name="x" class="size-4" />
                                            </button>
                                        </header>
                                        <div class="application-settings-section-body">
                                            <x-callout type="warning" title="Port requirement" class="mb-4">
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

                                            <div class="mt-4 flex flex-wrap justify-end gap-2 border-t border-neutral-200 pt-4 dark:border-border-subtle">
                                                <x-forms.button @click="modalOpen = false; $wire.call('cancelRemovePort')"
                                                    class="w-auto">
                                                    Keep port
                                                </x-forms.button>
                                                <x-forms.button wire:click="confirmRemovePort" @click="modalOpen = false" class="w-auto"
                                                    isError>
                                                    Remove port anyway
                                                </x-forms.button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    @endif
                @endif
            @elseif ($resourceType === 'database')
                <x-slot:title>
                    {{ data_get_str($service, 'name')->limit(10) }} >
                    {{ data_get_str($serviceDatabase, 'name')->limit(10) }} | Coolify
                </x-slot>
                @if ($currentRoute === 'project.service.database.import')
                    <livewire:project.database.import :resource="$serviceDatabase" :key="'import-' . $serviceDatabase->uuid" />
                @elseif ($currentRoute === 'project.service.index.advanced')
                    <section class="application-settings-section">
                        <div class="application-settings-section-header">
                            <div>
                                <h2>Advanced</h2>
                                <p>Control status aggregation and external log delivery.</p>
                            </div>
                        </div>
                        <div class="application-settings-section-body grid gap-4 sm:grid-cols-2">
                            <x-forms.listbox id="excludeFromStatus" label="Service status"
                                onChange="instantSaveExclude" :options="[
                                    ['value' => false, 'label' => 'Include in status'],
                                    ['value' => true, 'label' => 'Exclude from status'],
                                ]" />
                            <x-forms.listbox id="isLogDrainEnabled" label="Log drain"
                                onChange="instantSaveLogDrain" :options="[
                                    ['value' => true, 'label' => 'Send logs to drain'],
                                    ['value' => false, 'label' => 'Do not drain logs'],
                                ]" />
                        </div>
                    </section>
                @else
                    <form wire:submit="submitDatabase" class="space-y-6">
                        <x-unsaved-bar action="submitDatabase" />
                        <section class="application-settings-section">
                            <div class="application-settings-section-header">
                                <div>
                                    <h2>{{ Str::headline($serviceDatabase->human_name ?: $serviceDatabase->name) }}</h2>
                                    <p>Identity, image, and public access for this compose database.</p>
                                </div>
                                <div class="flex items-center gap-2">
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
                            </div>
                            <div class="application-settings-section-body space-y-5">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-forms.input canGate="update" :canResource="$serviceDatabase" label="Name" id="humanName"
                                    placeholder="Name"></x-forms.input>
                                <x-forms.input canGate="update" :canResource="$serviceDatabase" label="Description"
                                    id="description"></x-forms.input>
                                <x-forms.input class="sm:col-span-2" canGate="update" :canResource="$serviceDatabase" required
                                    helper="You can change the image you would like to deploy.<br><br><span class='dark:text-warning'>WARNING. You could corrupt your data. Only do it if you know what you are doing.</span>"
                                    label="Image" id="image"></x-forms.input>
                            </div>
                            <div class="border-t border-neutral-200 pt-5 dark:border-white/[0.06]">
                                <div class="mb-4 flex items-center justify-between gap-2">
                                    <h3 class="text-sm font-semibold text-black dark:text-fg">Public access</h3>
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
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <x-forms.checkbox canGate="update" :canResource="$serviceDatabase" instantSave id="isPublic"
                                        label="Make it publicly available" />
                                    <x-forms.input type="number" canGate="update" :canResource="$serviceDatabase" placeholder="5432"
                                        disabled="{{ $serviceDatabase->is_public }}" id="publicPort" label="Public Port" />
                                @if ($db_url_public)
                                    <x-forms.input class="sm:col-span-2" label="Database IP:PORT (public)"
                                        helper="Your credentials are available in your environment variables." type="password"
                                        readonly wire:model="db_url_public" />
                                @endif
                                </div>
                            </div>
                            </div>
                        </section>
                    </form>
                @endif
            @endif
        </div>
        </div>
    </section>
</div>
