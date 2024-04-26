<div>
    <form class="flex flex-col gap-2 pb-6" wire:submit='submit'>
        <div class="flex items-start gap-2">
            <div class="pb-4">
                <h1>Storage Details</h1>
                <div class="subtitle">{{ $storage->name }}</div>
                @if ($storage->is_usable)
                    <div>Usable</div>
                @else
                    <div class="text-red-500">Not Usable</div>
                @endif
            </div>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            <x-forms.button wire:click="test_s3_connection">
                Validate Connection
            </x-forms.button>
            <x-modal-confirmation isErrorButton buttonTitle="Delete">
                This storage will be deleted. It is not reversible. Your data won't be touched!<br>Please think again.
            </x-modal-confirmation>
        </div>
        <div class="flex gap-2">
            <x-forms.input label="Name" id="storage.name" />
            <x-forms.input label="Description" id="storage.description" />
        </div>
        <div class="flex gap-2">
            <x-forms.input required label="Endpoint" id="storage.endpoint" />
            <x-forms.input required label="Bucket" id="storage.bucket" />
            <x-forms.input required label="Region" id="storage.region" />
        </div>
        <div class="flex gap-2">
            <x-forms.input required type="password" label="Access Key" id="storage.key" />
            <x-forms.input required type="password" label="Secret Key" id="storage.secret" />
        </div>
    </form>
</div>
