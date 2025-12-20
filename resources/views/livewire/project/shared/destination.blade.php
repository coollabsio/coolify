<div>
    <h2>{{ __('destination.title') }}</h2>
    <div class="">{{ __('destination.description') }}</div>
    <div class="grid grid-cols-1 gap-4 py-4">
        <div class="flex flex-col gap-2">
            <h3>{{ __('destination.primary_server') }}</h3>
            <div
                class="relative flex flex-col bg-white border cursor-default dark:text-white box-without-bg dark:bg-coolgray-100 dark:border-coolgray-300">
                @if (str($resource->realStatus())->startsWith('running'))
                    <div title="{{ $resource->realStatus() }}" class="absolute bg-success -top-1 -left-1 badge ">
                    </div>
                @elseif (str($resource->realStatus())->startsWith('exited'))
                    <div title="{{ $resource->realStatus() }}" class="absolute bg-error -top-1 -left-1 badge ">
                    </div>
                @endif
                <div class="box-title">
                    {{ __('destination.server_label') }} {{ data_get($resource, 'destination.server.name') }}
                </div>
                <div class="box-description">
                    {{ __('destination.network_label') }} {{ data_get($resource, 'destination.network') }}
                </div>
            </div>
            @if ($resource?->additional_networks?->count() > 0)
                <div class="flex gap-2">
                    <x-forms.button
                        wire:click="redeploy('{{ data_get($resource, 'destination.id') }}','{{ data_get($resource, 'destination.server.id') }}')">{{ __('destination.deploy') }}</x-forms.button>
                    @if (str($resource->realStatus())->startsWith('running'))
                        <x-forms.button isError
                            wire:click="stop('{{ data_get($resource, 'destination.server.id') }}')">{{ __('destination.stop') }}</x-forms.button>
                    @endif
                </div>
            @endif
        </div>
        @if ($resource?->additional_networks?->count() > 0 && data_get($resource, 'build_pack') !== 'dockercompose')
            <h3>{{ __('destination.additional_servers') }}</h3>
            @foreach ($resource->additional_networks as $destination)
                <div class="flex flex-col gap-2" wire:key="destination-{{ $destination->id }}">
                    <div
                        class="relative flex flex-col bg-white border cursor-default dark:text-white box-without-bg dark:bg-coolgray-100 dark:border-coolgray-300">
                        @if (str(data_get($destination, 'pivot.status'))->startsWith('running'))
                            <div title="{{ data_get($destination, 'pivot.status') }}"
                                class="absolute bg-success -top-1 -left-1 badge "></div>
                        @elseif (str(data_get($destination, 'pivot.status'))->startsWith('exited'))
                            <div title="{{ data_get($destination, 'pivot.status') }}"
                                class="absolute bg-error -top-1 -left-1 badge "></div>
                        @endif
                        <div>
                            <div class="box-title">
                                {{ __('destination.server_label') }} {{ data_get($destination, 'server.name') }}
                            </div>
                            <div class="box-description">
                                {{ __('destination.network_label') }} {{ data_get($destination, 'network') }}
                            </div>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <x-forms.button
                            wire:click="redeploy('{{ data_get($destination, 'id') }}','{{ data_get($destination, 'server.id') }}')">{{ __('destination.deploy') }}</x-forms.button>
                        <x-forms.button
                            wire:click="promote('{{ data_get($destination, 'id') }}','{{ data_get($destination, 'server.id') }}')">{{ __('destination.promote_to_primary') }}</x-forms.button>
                        @if (data_get_str($destination, 'pivot.status')->startsWith('running'))
                            <x-forms.button isError
                                wire:click="stop('{{ data_get($destination, 'server.id') }}')">{{ __('destination.stop') }}</x-forms.button>
                        @endif
                        <x-modal-confirmation title="{{ __('destination.confirm_remove_title') }}" isErrorButton
                            buttonTitle="{{ __('destination.remove_from_server') }}"
                            submitAction="removeServer({{ data_get($destination, 'id') }},{{ data_get($destination, 'server.id') }})"
                            :actions="[
                                __('destination.remove_action'),
                            ]" confirmationText="{{ data_get($destination, 'server.name') }}"
                            confirmationLabel="{{ __('destination.confirm_label') }}"
                            shortConfirmationLabel="{{ __('destination.server_name_label') }}" />
                    </div>
                </div>
            @endforeach
        @endif
    </div>
    @if ($resource->getMorphClass() === 'App\Models\Application' && data_get($resource, 'build_pack') !== 'dockercompose')
        <div class="flex flex-col gap-2">
            @if ($resource->persistentStorages()->count() > 0)
                <h3>{{ __('destination.add_server') }}</h3>
                <x-callout type="warning" title="{{ __('destination.cannot_add_servers_title') }}">
                    {{ __('destination.persistent_storage_warning') }}
                </x-callout>
            @elseif (count($networks) > 0)
                <h3>{{ __('destination.add_server') }}</h3>
                <div class="grid grid-cols-1 gap-4">
                    @foreach ($networks as $network)
                        <div wire:click="addServer('{{ $network->id }}','{{ data_get($network, 'server.id') }}')"
                            class="relative flex flex-col dark:text-white coolbox group">
                            <div>
                                <div class="box-title">
                                    {{ __('destination.server_label') }} {{ data_get($network, 'server.name') }}
                                </div>
                                <div class="box-description">
                                    {{ __('destination.network_label') }} {{ data_get($network, 'name') }}
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div>{{ __('destination.no_servers_available') }}</div>
            @endif
        </div>
    @endif
</div>
