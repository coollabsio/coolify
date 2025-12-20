<form wire:submit.prevent='submit' class="flex flex-col gap-4 pb-2">
    <div>
        <div class="flex gap-2">
            <h2>{{ __('service.stack_heading') }}</h2>
            @if (isDev())
                <div>{{ $service->compose_parsing_version }}</div>
            @endif
            <x-forms.button canGate="update" :canResource="$service" wire:target='submit'
                type="submit">{{ __('button.save') }}</x-forms.button>
            @can('update', $service)
                <x-modal-input buttonTitle="{{ __('modal.edit_compose_file') }}" title="{{ __('modal.edit_docker_compose') }}" :closeOutside="false">
                    <livewire:project.service.edit-compose serviceId="{{ $service->id }}" />
                </x-modal-input>
            @endcan
        </div>
        <div>{{ __('menu.configuration') }}</div>
    </div>
    <div class="flex gap-2">
        <x-forms.input canGate="update" :canResource="$service" id="name" required label="{{ __('input.service_name') }}"
            placeholder="{{ __('input.service_name_placeholder') }}" />
        <x-forms.input canGate="update" :canResource="$service" id="description" label="{{ __('input.description') }}" />
    </div>
    <div class="w-96">
        <x-forms.checkbox canGate="update" :canResource="$service" instantSave id="connectToDockerNetwork"
            label="{{ __('service.connect_to_network') }}"
            helper="{{ __('service.connect_to_network_helper') }}" />
    </div>
    @if ($fields->count() > 0)
        <div>
            <h3>{{ __('service.specific_config') }}</h3>
        </div>
        <div class="grid grid-cols-2 gap-2">
            @foreach ($fields as $serviceName => $field)
                <div class="flex items-center gap-2"><span
                        class="font-bold">{{ data_get($field, 'serviceName') }}</span>{{ data_get($field, 'name') }}
                    @if (data_get($field, 'customHelper'))
                        <x-helper helper="{{ data_get($field, 'customHelper') }}" />
                    @else
                        <x-helper helper="{{ __('service.variable_name') }} {{ $serviceName }}" />
                    @endif
                </div>
                <x-forms.input canGate="update" :canResource="$service"
                    type="{{ data_get($field, 'isPassword') ? 'password' : 'text' }}"
                    required="{{ str(data_get($field, 'rules'))?->contains('required') }}"
                    id="fields.{{ $serviceName }}.value"></x-forms.input>
            @endforeach
        </div>
    @endif
</form>