<div>
    <x-slot:title>
        {{ $destination->name }} | Destination | Coolify
    </x-slot>

    <div class="flex flex-col">
        <header class="order-1 mb-4 min-w-0 lg:order-2 lg:mb-8">
            <h1 class="truncate text-[24px]! leading-7! font-semibold! tracking-tight!">
                {{ $name }}
            </h1>
            <p class="mt-1 text-[13px] text-neutral-500 dark:text-fg-dim">
                @if ($destination->getMorphClass() === 'App\Models\StandaloneDocker')
                    Docker network on {{ data_get($destination, 'server.name', 'server') }}
                @else
                    Deprecated Docker Swarm network
                @endif
            </p>
        </header>

        <div class="order-2 lg:order-1">
            @include('livewire.destination.navbar', ['destination' => $destination])
        </div>

        <form wire:submit="submit" class="order-3 application-settings-form">
            <x-unsaved-bar action="submit" />

            <x-application.settings-section title="General"
                :description="$destination->getMorphClass() === 'App\Models\StandaloneDocker'
                    ? 'Docker network used to connect deployed resources.'
                    : 'Deprecated Docker Swarm network.'">
                <x-slot:actions>
                    @if ($destination->getMorphClass() !== 'App\Models\StandaloneDocker')
                        <x-status-badge label="Deprecated" type="warning" />
                    @endif
                    @if ($network !== 'coolify')
                        <x-modal-confirmation title="Confirm Destination Deletion?"
                            buttonTitle="Delete destination" isErrorButton submitAction="delete"
                            :actions="['This will delete the selected destination/network.']"
                            confirmationText="{{ $destination->name }}"
                            confirmationLabel="Please confirm the execution of the actions by entering the Destination Name below"
                            shortConfirmationLabel="Destination Name" :confirmWithPassword="false"
                            step2ButtonText="Permanently Delete" canGate="delete"
                            :canResource="$destination" />
                    @endif
                </x-slot:actions>

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
    </div>
</div>
