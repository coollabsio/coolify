    <x-forms.select wire:model.live="selectedEnvironment">
        @foreach ($environments as $environment)
            <option value="{{ $environment->uuid }}">{{ $environment->name }}</option>
        @endforeach
        <option disabled>-----</option>
        <option value="edit">Create / Edit</option>
    </x-forms.select>
