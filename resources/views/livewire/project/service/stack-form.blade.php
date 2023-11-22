<form wire:submit.prevent='submit' class="flex flex-col gap-4 pb-2">
    <div class="flex gap-2">
        <div>
            <h2>Service Stack</h2>
            <div>Configuration</div>
        </div>
        <x-forms.button type="submit">Save</x-forms.button>
        <x-forms.button class="w-64" onclick="composeModal.showModal()">Edit Compose
            File</x-forms.button>
    </div>
    <div class="flex gap-2">
        <x-forms.input id="service.name" required label="Service Name" placeholder="My super wordpress site" />
        <x-forms.input id="service.description" label="Description" />
    </div>
    @if ($fields)
        <div>
            <h3>Service Specific Configuration</h3>
        </div>
        <div class="grid grid-cols-2 gap-2">
            @foreach ($fields as $serviceName => $field)
                <x-forms.input type="{{ data_get($field, 'isPassword') ? 'password' : 'text' }}" required
                    helper="Variable name: {{ $serviceName }}"
                    label="{{ data_get($field, 'serviceName') }} {{ data_get($field, 'name') }}"
                    id="fields.{{ $serviceName }}.value"></x-forms.input>
            @endforeach
        </div>
    @endif
</form>
