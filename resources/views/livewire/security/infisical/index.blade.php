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
                Sync secrets from Infisical into this team's variables.
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
                                        'Coolify will stop syncing secrets from Infisical for this team.',
                                        'Variables already synced into Coolify are kept, but stop being refreshed.',
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

    </div>
    </x-security.settings-layout>
</div>
