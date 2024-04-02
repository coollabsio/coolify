<div>
    <div class="subtitle">S3 Storage used to save backups / files.</div>
    <form class="flex flex-col gap-2" wire:submit='submit'>
        <div class="flex gap-2">
            <x-forms.input label="Name" id="name" />
            <x-forms.input label="Description" id="description" />
        </div>
        <div class="flex gap-2">
            <x-forms.input type="url" label="Endpoint" id="endpoint" />
            <x-forms.input required label="Bucket" id="bucket" />
            <x-forms.input required label="Region" id="region" />
        </div>
        <div class="flex gap-2">
            <x-forms.input required type="password" label="Access Key" id="key" />
            <x-forms.input required type="password" label="Secret Key" id="secret" />
        </div>

        <x-forms.button type="submit">
            Validate Connection & Continue
        </x-forms.button>
    </form>
</div>
