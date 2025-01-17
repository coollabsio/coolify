<div>
    <h2>Servers</h2>
    <div class="">Server related configurations.</div>
    <div class="grid grid-cols-1 gap-4 py-4">
        <div class="flex flex-col gap-2">
            <h3>Primary Server</h3>
            <div
                class="relative flex flex-col bg-white border cursor-default dark:text-white box-without-bg dark:bg-coolgray-100 w-96 dark:border-black">
                @if (str($resource->realStatus())->startsWith('running'))
                    <div title="{{ $resource->realStatus() }}" class="absolute bg-success -top-1 -left-1 badge ">
                    </div>
                @elseif (str($resource->realStatus())->startsWith('exited'))
                    <div title="{{ $resource->realStatus() }}" class="absolute bg-error -top-1 -left-1 badge ">
                    </div>
                @endif
                <div class="box-title">
                    Server: {{ data_get($resource, 'destination.server.name') }}
                </div>
                <div class="box-description">
                    Network: {{ data_get($resource, 'destination.network') }}
                </div>
            </div>
            @if ($resource?->additional_networks?->count() > 0)
                <div class="flex gap-2">
                    <x-forms.button
                        wire:click="redeploy('{{ data_get($resource, 'destination.id') }}','{{ data_get($resource, 'destination.server.id') }}')">Deploy</x-forms.button>
                    @if (str($resource->realStatus())->startsWith('running'))
                        <x-forms.button isError
                            wire:click="stop('{{ data_get($resource, 'destination.server.id') }}')">Stop</x-forms.button>
                    @endif
                </div>
            @endif
        </div>
        @if ($resource?->additional_networks?->count() > 0 && data_get($resource, 'build_pack') !== 'dockercompose')
            <h3>Additional Server(s)</h3>
            @foreach ($resource->additional_networks as $destination)
                <div class="flex flex-col gap-2">
                    <div class="relative flex flex-col w-full box">
                        @if (str(data_get($destination, 'pivot.status'))->startsWith('running'))
                            <div title="{{ data_get($destination, 'pivot.status') }}"
                                class="absolute bg-success -top-1 -left-1 badge "></div>
                        @elseif (str(data_get($destination, 'pivot.status'))->startsWith('exited'))
                            <div title="{{ data_get($destination, 'pivot.status') }}"
                                class="absolute bg-error -top-1 -left-1 badge "></div>
                        @endif
                        <div>
                            <div class="box-title">
                                Server: {{ data_get($destination, 'server.name') }}
                            </div>
                            <div class="box-description">
                                Network: {{ data_get($destination, 'network') }}
                            </div>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <x-forms.button
                            wire:click="redeploy('{{ data_get($destination, 'id') }}','{{ data_get($destination, 'server.id') }}')">Deploy</x-forms.button>
                        <x-forms.button
                            wire:click="promote('{{ data_get($destination, 'id') }}','{{ data_get($destination, 'server.id') }}')">Promote
                            to Primary </x-forms.button>
                        @if (data_get_str($destination, 'pivot.status')->startsWith('running'))
                            <x-forms.button isError
                                wire:click="stop('{{ data_get($destination, 'server.id') }}')">Stop</x-forms.button>
                        @endif
                        <x-modal-confirmation title="Confirm server removal?" isErrorButton buttonTitle="Remove Server"
                            submitAction="removeServer({{ data_get($destination, 'id') }},{{ data_get($destination, 'server.id') }})"
                            :actions="[
                                'This will stop the all running applications on this server and remove it as a deployment destination.',
                            ]" confirmationText="{{ data_get($destination, 'server.name') }}"
                            confirmationLabel="Please confirm the execution of the actions by entering the Server Name below"
                            shortConfirmationLabel="Server Name" step3ButtonText="Permanently Remove Server" />
                    </div>
                </div>
            @endforeach
        @endif
    </div>
    @if ($resource->getMorphClass() === 'App\Models\Application' && data_get($resource, 'build_pack') !== 'dockercompose')
        @if (count($networks) > 0)
            <h4>Choose another server</h4>
            <div class="grid grid-cols-1 gap-4">
                @foreach ($networks as $network)
                    <div wire:click="addServer('{{ $network->id }}','{{ data_get($network, 'server.id') }}')"
                        class="relative flex flex-col cursor-default dark:text-white box w-96 group">
                        <div>
                            <div class="box-title">
                                Server: {{ data_get($network, 'server.name') }}
                            </div>
                            <div class="box-description">
                                Network: {{ data_get($network, 'name') }}
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div>No additional servers available to attach.</div>
        @endif
    @endif
</div>
