<form wire:submit='submit' class="flex flex-col gap-4 pb-2">
    <div class="flex gap-2">
        <div>
            <h2>Service Stack</h2>
            <div>Configuration</div>
        </div>
        <x-forms.button type="submit">Save</x-forms.button>
        <x-forms.button class="w-64"
            onclick="Livewire.dispatch('openModal', {component: 'modal.edit-compose', arguments:  {{ json_encode(['serviceId' => $service->id]) }}  })">Edit
            Compose
            File</x-forms.button>
    </div>
    <div class="flex gap-2">
        <x-forms.input id="service.name" required label="Service Name" placeholder="My super wordpress site" />
        <x-forms.input id="service.description" label="Description" />
    </div>
    <div class="w-96">
        <x-forms.checkbox instantSave id="service.connect_to_docker_network" label="Connect To Predefined Network"
            helper="By default, you do not reach the Coolify defined networks.<br>Starting a docker compose based resource will have an internal network. <br>If you connect to a Coolify defined network, you maybe need to use different internal DNS names to connect to a resource.<br><br>For more information, check <a class='text-white underline' href='https://coolify.io/docs/docker/compose#connect-to-predefined-networks'>this</a>." />
    </div>
    @if ($fields)
        <div>
            <h3>Service Specific Configuration</h3>
        </div>
        <div class="grid grid-cols-2 gap-2">
            @foreach ($fields as $serviceName => $field)
                <x-forms.input type="{{ data_get($field, 'isPassword') ? 'password' : 'text' }}"
                    required="{{ str(data_get($field, 'rules'))?->contains('required') }}"
                    helper="Variable name: {{ $serviceName }}"
                    label="{{ data_get($field, 'serviceName') }} {{ data_get($field, 'name') }}"
                    id="fields.{{ $serviceName }}.value"></x-forms.input>
            @endforeach
        </div>
    @endif
</form>
