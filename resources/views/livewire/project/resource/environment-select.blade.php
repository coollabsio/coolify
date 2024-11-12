    <x-forms.select wire:model.live="selectedEnvironment">
        <option value="edit">Create / Edit</option>
        <option disabled>-----</option>
        @foreach ($environments as $environment)
            <option value="{{ $environment->name }}">{{ $environment->name }}
            </option>
        @endforeach
    </x-forms.select>
