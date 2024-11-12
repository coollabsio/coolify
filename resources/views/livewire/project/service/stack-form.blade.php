<form wire:submit.prevent='submit' class="flex flex-col gap-4 pb-2">
    <div>
        <div class="flex gap-2">
            <h2>Service Stack</h2>
            <x-forms.button wire:target='submit' type="submit">Save</x-forms.button>
            <x-modal-input buttonTitle="Edit Compose File" title="Edit Docker Compose" :closeOutside="false">
                <livewire:project.service.edit-compose serviceId="{{ $service->id }}" />
            </x-modal-input>
        </div>
        <div>Configuration</div>
    </div>
    <div class="flex gap-2">
        <x-forms.input id="service.name" required label="Service Name" placeholder="My super wordpress site" />
        <x-forms.input id="service.description" label="Description" />
    </div>
    <div class="w-96">
        <x-forms.checkbox instantSave id="service.connect_to_docker_network" label="Connect To Predefined Network"
            helper="By default, you do not reach the Coolify defined networks.<br>Starting a docker compose based resource will have an internal network. <br>If you connect to a Coolify defined network, you maybe need to use different internal DNS names to connect to a resource.<br><br>For more information, check <a class='underline dark:text-white' target='_blank' href='https://coolify.io/docs/knowledge-base/docker/compose#connect-to-predefined-networks'>this</a>." />
    </div>
    @if ($fields->count() > 0)
        <div>
            <h3>Service Specific Configuration</h3>
        </div>
        <div class="grid grid-cols-2 gap-2">
            @foreach ($fields as $serviceName => $field)
                <div class="flex items-center gap-2"><span
                        class="font-bold">{{ data_get($field, 'serviceName') }}</span>{{ data_get($field, 'name') }}
                        @if (data_get($field, 'customHelper'))
                            <x-helper helper="{{ data_get($field, 'customHelper') }}" />
                        @else
                            <x-helper helper="Variable name: {{ $serviceName }}" />
                        @endif
                    </div>
                <x-forms.input type="{{ data_get($field, 'isPassword') ? 'password' : 'text' }}"
                    required="{{ str(data_get($field, 'rules'))?->contains('required') }}"
                    id="fields.{{ $serviceName }}.value"></x-forms.input>
            @endforeach
        </div>
    @endif
</form>
