<div>
    <x-slot:title>
        {{ data_get_str($server, 'name')->limit(10) }} > Docker Registries | Coolify
    </x-slot>
    <livewire:server.navbar :server="$server" />
    <div class="flex flex-col h-full gap-8 sm:flex-row">
        <x-server.sidebar :server="$server" activeMenu="docker-registries" />
        <div class="w-full">
            <div class="flex items-center justify-between mb-6">
                <h2>Docker Registries</h2>
                @can('update', $server)
                    <div class="flex gap-2">
                        <x-forms.button wire:click="importFromServer" :disabled="$importing">
                            @if ($importing)
                                <x-loading class="dark:text-warning" />
                            @endif
                            Import from Server
                        </x-forms.button>
                        <x-forms.button wire:click="openAddModal">
                            Add Registry
                        </x-forms.button>
                    </div>
                @endcan
            </div>

            <div class="pb-6">
                <p class="text-sm dark:text-neutral-400">
                    Configure Docker registry authentication for pulling private images during deployments.
                    Credentials are stored securely and synced to the server's <code class="text-xs">~/.docker/config.json</code> file.
                </p>
            </div>

            @if (empty($registries))
                <div class="flex flex-col items-center justify-center p-12 border-2 border-dashed border-neutral-300 dark:border-coolgray-300 rounded-lg">
                    <svg class="w-16 h-16 mb-4 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                            d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                    </svg>
                    <h3 class="mb-2 text-lg font-medium">No registries configured</h3>
                    <p class="mb-4 text-sm text-neutral-500 dark:text-neutral-400">Get started by adding a Docker registry or importing from your server.</p>
                    @can('update', $server)
                        <div class="flex gap-2">
                            <x-forms.button wire:click="importFromServer" :disabled="$importing">
                                Import from Server
                            </x-forms.button>
                            <x-forms.button wire:click="openAddModal">
                                Add Registry
                            </x-forms.button>
                        </div>
                    @endcan
                </div>
            @else
                <div class="space-y-3">
                    @foreach ($registries as $registry)
                        <div class="p-4 border dark:border-coolgray-300 rounded-lg">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center gap-3 mb-2">
                                        <h3 class="font-medium">{{ $registry['name'] }}</h3>
                                        @if ($registry['is_active'])
                                            <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400">
                                                Active
                                            </span>
                                        @else
                                            <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-neutral-100 text-neutral-800 dark:bg-neutral-800 dark:text-neutral-400">
                                                Disabled
                                            </span>
                                        @endif
                                        @if ($registry['last_validated_at'])
                                            <span class="text-xs text-neutral-500 dark:text-neutral-400">
                                                Validated {{ \Carbon\Carbon::parse($registry['last_validated_at'])->diffForHumans() }}
                                            </span>
                                        @endif
                                    </div>
                                    <div class="space-y-1 text-sm">
                                        <div class="flex gap-2">
                                            <span class="font-medium text-neutral-600 dark:text-neutral-400">URL:</span>
                                            <code class="text-xs">{{ $registry['registry_url'] }}</code>
                                        </div>
                                        <div class="flex gap-2">
                                            <span class="font-medium text-neutral-600 dark:text-neutral-400">Username:</span>
                                            <span>{{ $registry['username'] }}</span>
                                        </div>
                                    </div>
                                </div>
                                @can('update', $server)
                                    <div class="flex gap-2">
                                        <x-forms.button wire:click="toggleActive({{ $registry['id'] }})" type="button" class="!px-3 !py-1.5 text-sm">
                                            {{ $registry['is_active'] ? 'Disable' : 'Enable' }}
                                        </x-forms.button>
                                        <x-forms.button wire:click="editRegistry({{ $registry['id'] }})" type="button" class="!px-3 !py-1.5 text-sm">
                                            Edit
                                        </x-forms.button>
                                        <x-modal-confirmation
                                            title="Delete Registry?"
                                            buttonTitle="Delete"
                                            submitAction="deleteRegistry({{ $registry['id'] }})"
                                            :actions="['This will remove the registry from Coolify and update the server\'s config.']"
                                            confirmationText="{{ $registry['registry_url'] }}"
                                            shortConfirmationLabel="Registry URL"
                                            step3ButtonText="Delete Registry"
                                            :buttonClasses="'!px-3 !py-1.5 text-sm'"
                                        />
                                    </div>
                                @endcan
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if (!empty($commonRegistries))
                <div class="mt-8">
                    <h3 class="mb-4 text-lg font-medium">Quick Add</h3>
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
                        @foreach ($commonRegistries as $preset)
                            <button
                                wire:click="openAddModal({{ json_encode($preset) }})"
                                @cannot('update', $server) disabled @endcannot
                                class="p-4 text-left border dark:border-coolgray-300 rounded-lg hover:bg-neutral-50 dark:hover:bg-coolgray-100 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <div class="font-medium">{{ $preset['name'] }}</div>
                                <div class="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{{ $preset['registry_url'] }}</div>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Add/Edit Modal --}}
    @if ($showAddModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click.self="showAddModal = false">
            <div class="w-full max-w-2xl p-6 bg-white dark:bg-coolgray-100 rounded-lg shadow-xl" @click.stop>
                <h3 class="mb-4 text-xl font-medium">{{ $editingRegistry ? 'Edit' : 'Add' }} Docker Registry</h3>

                <div class="space-y-4">
                    <div>
                        <x-forms.input
                            wire:model="form.name"
                            id="registry-name"
                            label="Name"
                            placeholder="My Registry"
                            helper="A friendly name for this registry"
                        />
                    </div>

                    <div>
                        <x-forms.input
                            wire:model="form.registry_url"
                            id="registry-url"
                            label="Registry URL"
                            placeholder="https://index.docker.io/v1/ or ghcr.io"
                            helper="Full registry URL (e.g., https://index.docker.io/v1/ for Docker Hub)"
                        />
                    </div>

                    <div>
                        <x-forms.input
                            wire:model="form.username"
                            id="registry-username"
                            label="Username"
                            placeholder="username"
                        />
                    </div>

                    <div>
                        <x-forms.input
                            wire:model="form.password"
                            id="registry-password"
                            type="password"
                            label="Password or Token"
                            placeholder="••••••••"
                            helper="Password, access token, or API key"
                        />
                    </div>

                    <div>
                        <x-forms.checkbox
                            wire:model="form.is_active"
                            id="registry-is-active"
                            label="Active"
                            helper="Enable this registry for use during deployments"
                        />
                    </div>
                </div>

                <div class="flex items-center justify-between mt-6 pt-4 border-t dark:border-coolgray-300">
                    <div class="flex gap-2">
                        <x-forms.button wire:click="validateCredentials" type="button" :disabled="$validating">
                            @if ($validating)
                                <x-loading class="dark:text-warning" />
                            @endif
                            Test Connection
                        </x-forms.button>
                    </div>
                    <div class="flex gap-2">
                        <x-forms.button wire:click="showAddModal = false" type="button" class="bg-transparent hover:bg-neutral-100 dark:hover:bg-coolgray-200">
                            Cancel
                        </x-forms.button>
                        <x-forms.button wire:click="saveRegistry" type="button">
                            {{ $editingRegistry ? 'Update' : 'Add' }} Registry
                        </x-forms.button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
