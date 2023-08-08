<div>
    <x-modal yesOrNo modalId="deleteS3Storage" modalTitle="Delete S3 Storage">
        <x-slot:modalBody>
            <p>This storage will be deleted. It is not reversible. Your data won't be touched!<br>Please think again..
            </p>
        </x-slot:modalBody>
    </x-modal>
    <form class="flex flex-col gap-2 pb-6" wire:submit.prevent='submit'>
        <div class="flex items-start gap-2">
            <div class="pb-4">
                <h2>Storage Details</h2>
                <div>{{ $storage->name }}</div>
            </div>
            <x-forms.button type="submit">
                Save
            </x-forms.button>
            <x-forms.button wire:click="test_s3_connection">
                Test Connection
            </x-forms.button>
            <x-forms.button isError isModal modalId="deleteS3Storage">
                Delete
            </x-forms.button>
        </div>
        <div class="flex gap-2">
            <x-forms.input label="Name" id="storage.name"/>
            <x-forms.input label="Description" id="storage.description"/>
        </div>
        <div class="flex gap-2">
            <x-forms.input required label="Endpoint" id="storage.endpoint"/>
            <x-forms.input required label="Bucket" id="storage.bucket"/>
            <x-forms.input required label="Region" id="storage.region"/>
        </div>
        <div class="flex gap-2">
            <x-forms.input required type="password" label="Access Key" id="storage.key"/>
            <x-forms.input required type="password" label="Secret Key" id="storage.secret"/>
        </div>
    </form>
</div>
