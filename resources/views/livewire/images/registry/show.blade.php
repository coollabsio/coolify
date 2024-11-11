<div class="flex flex-col p-4 bg-white dark:bg-base border border-coolgray-200 dark:border-coolgray-700">
    <form wire:submit="updateRegistry" class="space-y-4">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-2">
                <x-forms.button type="submit">Update</x-forms.button>
                <x-modal-confirmation title="Confirm Registry Deletion?" isErrorButton buttonTitle="Delete"
                    submitAction="delete" :actions="['The selected registry will be permanently deleted.']" confirmationText="{{ $registry->name }}"
                    confirmationLabel="Please confirm by entering the registry name"
                    shortConfirmationLabel="Registry Name" :confirmWithPassword="false" step2ButtonText="Permanently Delete" />
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <x-forms.input wire:model="name" label="Name" required />

            <x-forms.select wire:model.live="type" label="Type">
                @foreach ($this->registryTypes as $key => $value)
                    <option value="{{ $key }}">{{ $value }}</option>
                @endforeach
            </x-forms.select>

            @if ($type === 'custom')
                <x-forms.input wire:model="url" label="URL" placeholder="registry.example.com" required />
            @endif

            <x-forms.input wire:model="username" label="Username" placeholder="Username for authentication" />

            <x-forms.input wire:model="token" type="password" label="Token/Password"
                placeholder="Authentication token or password" />
        </div>
    </form>
</div>
