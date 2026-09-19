<div>
    <x-slot:title>
        Infisical | Coolify
    </x-slot>

    <x-security.settings-layout>
    <div class="application-settings-form w-full">
    <header class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">Infisical</h1>
            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                Sync secrets from Infisical into an environment's shared variables.
            </p>
        </div>
    </header>

    <x-application.settings-section id="infisical-connections-section" title="Connections"
        description="Credentials Coolify uses to authenticate against an Infisical instance.">
        <x-slot:actions>
            @can('create', App\Models\InfisicalConnection::class)
                <x-modal-input title="New Infisical connection" :closeOutside="false">
                    <x-slot:content>
                        <button type="button" class="button button-highlighted">
                            <x-reicon name="plus" class="size-3.5" />
                            New connection
                        </button>
                    </x-slot:content>
                    <livewire:security.infisical.form wire:key="infisical-connection-new" />
                </x-modal-input>
            @endcan
        </x-slot:actions>

        @if ($this->connections->isEmpty())
            <x-empty size="sm" title="No Infisical connections yet"
                description="Add a connection to start syncing secrets from Infisical." />
        @else
            <div class="data-table w-full">
                @foreach ($this->connections as $connection)
                    <div wire:key="infisical-connection-{{ $connection->uuid }}"
                        class="data-table-row flex w-full items-center gap-2 px-3 py-2.5 text-[13px]">
                        <x-modal-input title="{{ $connection->name }}" :closeOutside="false">
                            <x-slot:content>
                                <button type="button"
                                    class="flex min-w-0 flex-1 items-center justify-between gap-3 text-left">
                                    <span class="truncate font-medium text-black dark:text-fg">{{ $connection->name }}</span>
                                    <span class="truncate text-neutral-500 dark:text-fg-dim">{{ $connection->host }}</span>
                                </button>
                            </x-slot:content>
                            <livewire:security.infisical.form :connection="$connection" :key="'infisical-connection-form-'.$connection->uuid" />
                        </x-modal-input>
                        @can('delete', $connection)
                            <div class="ml-auto shrink-0">
                                <x-modal-confirmation title="Confirm Connection Deletion?" isErrorButton
                                    buttonTitle="Delete" submitAction="deleteConnection('{{ $connection->uuid }}')"
                                    :actions="[
                                        'This Infisical connection will be permanently deleted.',
                                        'Every environment binding using it will be deleted.',
                                        'Every shared variable those bindings synced will be removed from Coolify.',
                                    ]" confirmationText="{{ $connection->name }}"
                                    confirmationLabel="Enter the connection name to confirm deletion"
                                    shortConfirmationLabel="Connection name" :confirmWithPassword="false"
                                    step2ButtonText="Delete connection" />
                            </div>
                        @endcan
                    </div>
                @endforeach
            </div>
        @endif
    </x-application.settings-section>

    <x-application.settings-section id="infisical-bindings-section" title="Environment bindings"
        description="Bind a Coolify environment to an Infisical project and environment slug.">
        @can('create', App\Models\InfisicalBinding::class)
            <div class="mb-4">
                <form wire:submit="createBinding" class="grid gap-4 lg:grid-cols-2">
                    <x-forms.listbox id="bindingConnectionId" label="Connection" :options="$this->connections->map(fn ($connection) => [
                        'value' => $connection->id,
                        'label' => $connection->name,
                    ])->values()->all()" />
                    <x-forms.listbox id="bindingEnvironmentId" label="Environment" :options="$this->availableEnvironments->map(fn ($environment) => [
                        'value' => $environment->id,
                        'label' => ($environment->project?->name ? $environment->project->name.' / ' : '').$environment->name,
                    ])->values()->all()" />
                    <x-forms.input required label="Infisical project ID" id="bindingProjectId" />
                    <x-forms.input required label="Infisical environment slug" id="bindingEnvironmentSlug"
                        placeholder="prod" />
                    <x-forms.input label="Secret path" id="bindingSecretPath" placeholder="/" />
                    <div class="flex items-end">
                        <x-forms.button type="submit" isHighlighted>Bind environment</x-forms.button>
                    </div>
                </form>
            </div>
        @endcan

        @if ($this->bindings->isEmpty())
            <x-empty size="sm" title="No bindings yet"
                description="Bind an environment above to start syncing its secrets." />
        @else
            <div class="data-table w-full">
                <div class="data-table-header env-table-grid-no-type">
                    <span>Environment</span>
                    <span>Infisical project</span>
                    <span class="text-center">Status</span>
                    <span></span>
                </div>
                @foreach ($this->bindings as $binding)
                    <div wire:key="infisical-binding-{{ $binding->uuid }}"
                        class="data-table-row env-table-grid-no-type items-center">
                        <div class="min-w-0 truncate">
                            {{ $binding->connection?->name }} /
                            {{ $binding->environment?->project?->name }} / {{ $binding->environment?->name }}
                        </div>
                        <div class="min-w-0 truncate text-neutral-500 dark:text-fg-dim">
                            {{ $binding->infisical_project_id }} ({{ $binding->infisical_environment_slug }})
                        </div>
                        <div class="text-center">
                            @if (! $binding->is_enabled)
                                <span class="table-badge">Disabled</span>
                            @elseif ($binding->last_sync_status === 'success')
                                <span class="table-badge table-badge-success">Synced</span>
                            @elseif ($binding->last_sync_status === 'failed')
                                <span class="table-badge table-badge-danger">Failed</span>
                            @else
                                <span class="table-badge">Never synced</span>
                            @endif
                        </div>
                        <div class="flex items-center justify-end gap-2 justify-self-end">
                            @can('update', $binding)
                                <x-forms.button type="button" wire:click="syncNow('{{ $binding->uuid }}')"
                                    wire:loading.attr="disabled" wire:target="syncNow('{{ $binding->uuid }}')">
                                    Sync now
                                </x-forms.button>
                                <x-forms.button type="button" wire:click="toggleBinding('{{ $binding->uuid }}')"
                                    wire:loading.attr="disabled" wire:target="toggleBinding('{{ $binding->uuid }}')">
                                    {{ $binding->is_enabled ? 'Disable' : 'Enable' }}
                                </x-forms.button>
                            @endcan
                            @can('delete', $binding)
                                <x-modal-confirmation title="Confirm Binding Deletion?" isErrorButton
                                    buttonTitle="Delete" submitAction="deleteBinding('{{ $binding->uuid }}')"
                                    :actions="[
                                        'This Infisical binding will be permanently deleted.',
                                        'Every shared variable it synced will be removed from this environment, and resources that read them will lose those values on their next deployment.',
                                        'Disable the binding instead if you want to keep the synced values.',
                                    ]" confirmationText="{{ $binding->environment?->name }}"
                                    confirmationLabel="Enter the environment name to confirm deletion"
                                    shortConfirmationLabel="Environment name" :confirmWithPassword="false"
                                    step2ButtonText="Delete binding" />
                            @endcan
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-application.settings-section>
    </div>
    </x-security.settings-layout>
</div>
