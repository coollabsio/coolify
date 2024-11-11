<form wire:submit="submit" class="flex flex-col gap-4 w-full">
    <x-forms.input wire:model="name" required id="name" label="Registry Name" placeholder="My Docker Hub" />

    <x-forms.select wire:model.live="type" label="Registry Type">
        @foreach ($this->registryTypes as $key => $value)
            <option value="{{ $key }}">{{ $value }}</option>
        @endforeach
    </x-forms.select>

    @if ($type === 'custom')
        <x-forms.input wire:model="url" required id="url" label="Registry URL"
            placeholder="registry.example.com" />
    @endif

    <x-forms.input wire:model="username" id="username" label="Username" placeholder="Username for authentication" />

    <x-forms.input wire:model="token" type="password" id="token" label="Token/Password"
        placeholder="Authentication token or password" />

    <div class="flex gap-2 w-full">
        <x-forms.button type="submit" class="w-full">Save Registry</x-forms.button>
    </div>
</form>
