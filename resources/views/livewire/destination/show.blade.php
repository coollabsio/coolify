<div>
    <x-slot:title>
        {{ $destination->name }} | Destination | Coolify
    </x-slot>

    @php
        $destinationSubtitle = $destination->getMorphClass() === 'App\Models\StandaloneDocker'
            ? 'Docker network on '.data_get($destination, 'server.name', 'server')
            : 'Deprecated Docker Swarm network';
    @endphp

    <x-dashboard.navbar section="destination" :parameters="['destination_uuid' => $destination->uuid]"
        :title="$name" :subtitle="$destinationSubtitle" :mobileTitleOnly="true" />

    <section class="application-settings-workspace mt-4 w-full max-w-none lg:mt-0">
        <div class="grid min-w-0 gap-8 xl:grid-cols-[210px_minmax(0,1fr)] xl:gap-8">
            @include('livewire.destination.sidebar', ['destination' => $destination])

            <div class="min-w-0">
                @if (request()->routeIs('destination.danger'))
                    <div class="application-settings-form">
                        <x-application.settings-section id="destination-danger-section" title="Danger zone"
                            helper="Destructive actions for this destination cannot be undone.">
                            <div
                                class="rounded-lg border border-red-300 bg-red-50 p-4 ring-1 ring-inset ring-red-200/60 dark:border-error/30 dark:bg-error/[0.08] dark:ring-error/10">
                                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <h4 class="text-sm font-semibold text-red-700 dark:text-error">Delete destination</h4>
                                            <x-status-badge status="Permanent" type="error" />
                                        </div>
                                        <p class="mt-2 max-w-2xl text-[13px] leading-5 text-neutral-600 dark:text-fg-dim">
                                            Permanently delete <strong class="font-semibold text-black dark:text-fg">{{ $destination->name }}</strong>
                                            from Coolify. The Docker network is also removed from the server.
                                        </p>
                                        <p class="mt-2 text-xs text-neutral-500 dark:text-fg-dim">
                                            Delete or move every attached resource before deleting this destination.
                                        </p>
                                    </div>
                                    @if ($network !== 'coolify')
                                        <x-modal-confirmation title="Confirm Destination Deletion?"
                                            buttonTitle="Delete destination" isErrorButton submitAction="delete"
                                            :actions="['This permanently deletes the destination and its Docker network.']"
                                            confirmationText="{{ $destination->name }}"
                                            confirmationLabel="Please confirm by entering the Destination Name below"
                                            shortConfirmationLabel="Destination Name" :confirmWithPassword="false"
                                            step2ButtonText="Permanently Delete" canGate="delete"
                                            :canResource="$destination" />
                                    @else
                                        <x-forms.button disabled tooltip="The default Coolify destination cannot be deleted.">
                                            Delete destination
                                        </x-forms.button>
                                    @endif
                                </div>
                            </div>
                        </x-application.settings-section>
                    </div>
                @else
                    <form wire:submit="submit" class="application-settings-form">
                    <x-unsaved-bar action="submit" />

                    <x-application.settings-section title="General"
                        :description="$destination->getMorphClass() === 'App\Models\StandaloneDocker'
                            ? 'Docker network used to connect deployed resources.'
                            : 'Deprecated Docker Swarm network.'">
                    @if ($destination->getMorphClass() !== 'App\Models\StandaloneDocker')
                        <x-slot:actions>
                            <x-status-badge label="Deprecated" type="warning" />
                        </x-slot:actions>
                    @endif

                        <div class="grid gap-4 lg:grid-cols-2">
                            <x-forms.input canGate="update" :canResource="$destination" id="name" label="Name" />
                            <x-forms.input id="serverIp" label="Server IP" readonly />
                            @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
                                <div class="lg:col-span-2">
                                    <x-forms.input id="network" label="Docker network" readonly />
                                </div>
                            @endif
                        </div>
                    </x-application.settings-section>
                    </form>
                @endif
            </div>
        </div>
    </section>
</div>
